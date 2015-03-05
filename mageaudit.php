<?php
/**
* NOTICE OF LICENSE
*
* Copyright 2013 Guidance Solutions
*
* Licensed under the Apache License, Version 2.0 (the "License");
* you may not use this file except in compliance with the License.
* You may obtain a copy of the License at
*
* http://www.apache.org/licenses/LICENSE-2.0
*
* Unless required by applicable law or agreed to in writing, software
* distributed under the License is distributed on an "AS IS" BASIS,
* WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
* See the License for the specific language governing permissions and
* limitations under the License.

* @author Gordon Knoppe
* @category Guidance
* @package Mageaudit
* @copyright Copyright (c) 2013 Guidance Solutions (http://www.guidance.com)
* @license http://www.apache.org/licenses/LICENSE-2.0 Apache License 2.0
*/

// Initialize Magento
require 'app/Mage.php';
Mage::app('admin', 'store');

function getRewrites($classType, $sorted = true)
{
    $config = Mage::getConfig();
    $rewrites = array();
    switch ($classType) {
        case 'controllers':
            foreach (array('admin', 'frontend') as $type) {
                $controllers = $config->getNode($type . '/routers')->asArray();
                foreach ($controllers as $router => $args) {
                    if (!isset($args['args']['modules'])) {
                        continue;
                    }
                    foreach ($args['args']['modules'] as $modules) {
                        if (
                            !isset($modules['@'])
                            || !isset($modules['@']['before'])
                            || strpos($modules[0], 'Mage_') === 0
                            || strpos($modules[0], 'Enterprise_') === 0
                        ) {
                            continue;
                        }
                        $moduleName = implode(
                            '_',
                            array_slice(
                                explode(
                                    '_',
                                    $modules[0]
                                ), 0, 2
                            )
                        );
                        $files = getControllerFiles(
                            Mage::getModuleDir('controllers', $moduleName)
                        );
                        foreach ($files as $file) {
                            preg_match_all(
                                '/class\s([a-z_]+)\sextends\s([a-z_]+)/i',
                                file_get_contents($file),
                                $matches,
                                PREG_PATTERN_ORDER
                            );
                            $class   = isset($matches[1][0]) ? trim($matches[1][0]) : false;
                            $extends = isset($matches[2][0]) ? trim($matches[2][0]) : false;
                            if (strpos(
                                $extends,
                                $modules['@']['before']
                            ) !== false) {
                                $rewrites[$class] = array(
                                    'alias' => $extends,
                                    'class' => $class
                                );
                                // controller classes don't autoload so include
                                // them manually
                                if (isset($_GET['methods'])) {
                                    include_once $file;
                                }
                            }
                        }
                    }
                }
            }
            break;
        default:
            $configNode = 'global/' . $classType;
            $models = $config->getNode($configNode)->asArray();
            foreach ($models as $package => $config) {
                if (isset($config['rewrite'])) {
                    foreach ($config['rewrite'] as $alias => $class) {
                        $classAlias = $package . '/' . $alias;
                        $rewrites[$classAlias] = array(
                            'alias' => $classAlias,
                            'class' => $class
                        );
                    }
                }
            }
    }
    if ($sorted) {
        ksort($rewrites);
    }
    return $rewrites;
}

function getControllerFiles($dir, $files = array())
{
    $contents = scandir($dir);
    foreach ($contents as $file) {
        if (in_array($file, array('.', '..'))) {
            continue;
        }
        $file = $dir . '/' . $file;
        if (substr($file, -14) == 'Controller.php') {
            $files[] = $file;
        } else if (is_dir($file)) {
            $files += getControllerFiles($file, $files);
        }
    }
    return $files;
}

function getOverridenMethods($className)
{
    if (class_exists($className)) {
        $overridenMethods = array();
        $class = new ReflectionClass($className);
        $parentMethods = array();
        $parent = $class->getParentClass();
        if ($parent) {
            foreach ($parent->getMethods() as $method) {
                $parentMethods[] = $method->getName();
            }
        }
        foreach ($class->getMethods() as $method) {
            if (
                $method->getDeclaringClass()->getName() == $className
                && in_array($method->getName(), $parentMethods)
            ) {
                $overridenMethods[] = $method->getName();
            }
        }
        sort($overridenMethods);
    } else {
        $overridenMethods[] = 'Class "' . $className . '" does not exist';
    }

    return $overridenMethods;
}

function getModules($sorted = true)
{
    $config = Mage::getConfig();
    $configNode = 'modules';
    $modules = $config->getNode($configNode)->asArray();
    $codePools = $dependancies = array();
    foreach ($modules as $package => $config) {
        if (isset($config['codePool'])) {
            $codePool = $config['codePool'];
            $codePools[$codePool][] = array_merge(
                $config,
                array('name' => $package)
            );
            if (!isset($config['depends']) || !is_array($config['depends'])) {
                continue;
            }
            foreach ($config['depends'] as $name => $value) {
                if (!isset($dependancies[$name])) {
                    $dependancies[$name] = array();
                }
                $dependancies[$name][] = $package;
            }
        }
    }
    if ($sorted) {
        foreach (array_keys($codePools) as $codePool) {
            usort($codePools[$codePool], 'sortModules');
        }
    }
    return array($codePools, $dependancies);
}

function sortModules($a, $b)
{
    return strcmp($a['name'], $b['name']);
}

function getAllObservers()
{
    $config = Mage::getConfig();
    $configNodes = array('global/events','frontend/events');
    $observers = array();
    foreach($configNodes as $configNode) {
        $eventConfig = $config->getNode($configNode);
        foreach ($eventConfig->children() as $eventName => $obsConfig) {
            foreach($obsConfig->observers->children() as $observerConfig) {
                if (!preg_match('/^(Mage|Enterprise)_/', $observerConfig->getClassName())) {
                    if (!isset($observers[$eventName])) {
                        $observers[$eventName] = array();
                    }
                    $observers[$eventName][] = $observerConfig->getClassName()
                        . '::' . (string)$observerConfig->method;
                }
            }
        }
    }
    ksort($observers);
    return $observers;
}

function getStores()
{
    $data = array();
    foreach (Mage::getModel('core/website')->getCollection() as $website) {
        /** @var $website Mage_Core_Model_Website */
        $groupCollection = $website->getGroupCollection();
        $data[$website->getId()] = array(
            'object' => $website,
            'storeGroups' => array(),
            'count' => 0
        );
        $defaultGroupId = $website->getDefaultGroupId();
        foreach ($groupCollection as $storeGroup) {
            /** @var $storeGroup Mage_Core_Model_Store_Group */
            $storeCollection = $storeGroup->getStoreCollection();
            $storeGroupCount = max(1, $storeCollection->count());
            $data[$website->getId()]['storeGroups'][$storeGroup->getId()] = array(
                'object' => $storeGroup,
                'stores' => array(),
                'count' => $storeGroupCount
            );
            $data[$website->getId()]['count'] += $storeGroupCount;
            if ($storeGroup->getId() == $defaultGroupId) {
                $storeGroup->setData('is_default', true);
            }
            $defaultStoreId = $storeGroup->getDefaultStoreId();
            foreach ($storeCollection as $store) {
                /** @var $store Mage_Core_Model_Store */
                $data[$website->getId()]['storeGroups'][$storeGroup->getId()]['stores'][$store->getId()] = array(
                    'object' => $store
                );
                if ($store->getId() == $defaultStoreId) {
                    $store->setData('is_default', true);
                }
            }
        }

        $data[$website->getId()]['count'] = max(1, $data[$website->getId()]['count']);
    }
    return $data;
}

function getCache()
{
    $options = array();
    $config = Mage::app()->getConfig()->getXpath('global');
    $config = $config[0];
    if (isset($config->session_save)) {
        $value = (string) $config->session_save;
        if ($value == 'db') {
            $value = 'Database';
            if (isset($config->session_save_path)) {
                $value = 'Memcached';
            } elseif (isset($config->redis_session)) {
                $value = 'Redis';
            }
        }
        $options['Session'] = $value;
    }
    if (isset($config->cache)) {
        $value = (string) $config->cache->backend;
        if (!$value) {
            $value = 'File System';
        }
        $options['Cache'] = $value;
    }
    if (isset($config->full_page_cache)) {
        $value = (string) $config->full_page_cache->backend;
        if (!$value) {
            $value = 'File System';
        }
        $options['Full Page Cache'] = $value;
    }
    return $options;
}

list($codePools, $dependancies) = getModules();

// Get all system rewrites
$rewriteTypes = array('blocks', 'controllers', 'helpers', 'models');
$systemRewrites = array();
foreach ($rewriteTypes as $rewriteType) {
    $systemRewrites[$rewriteType] = getRewrites($rewriteType);
}

// Sort rewrites by module
$moduleRewrites = array();
foreach ($rewriteTypes as $rewriteType) {
    foreach ($systemRewrites[$rewriteType] as $rewrite) {
        $module = explode('_', $rewrite['class']);
        $module = $module[0] . '_' . $module[1];
        $moduleRewrites[$rewriteType][$module][] = $rewrite;
    }
}

?>
<html>
<head>
    <title>Magento Audit Report - <?php echo $_SERVER['HTTP_HOST'];?></title>
    <style type="text/css">
        body {
            font-family: sans-serif;
        }
        th, td {
            border: 1px solid #e8e8e8;
            padding: .5em
        }
        table.summary {
            min-width: 300px;
        }
        table.summary td {
            height: 40px;
            text-align: right;
        }
        table.summary tbody td {
            text-align: left;
        }
        table.summary td.observer {
            text-align: left;
        }
        table.summary th {
            text-align: left;
        }
        table.summary thead th {
            text-align: center;
        }
        table.modules {
            width: 100%;
        }
        table.modules td {
            padding-bottom: 100px;
        }
        table.rewrites {
            width: 100%;
        }
        table.rewrites td {
            padding-bottom: 100px;
        }
        table.rewrites p {
            font-weight: bold;
        }
        .module {
            border: 1px solid #e8e8e8;
            padding: 0.5em;
            margin-bottom: 1em;
        }
        #nav {
            position: fixed;
            right: 20px;
            top: 0;
            border: 2px solid black;
            padding: 20px;
            list-style-type: none;
            background: white;
        }
        #nav li + li {
            margin-top: 5px;
        }
    </style>
</head>
<body>
<h1>Magento Audit</h1>
<ul id="nav">
    <li><a href="#stats">Statistics</a></li>
    <li><a href="#stores">Stores</a></li>
    <li><a href="#cache">Cache</a></li>
    <li><a href="#products">Products</a></li>
    <li><a href="#observers">Observers</a></li>
    <li><a href="#modules">Modules</a></li>
</ul>
<h2 id="stats">Statistics</h2>
<?php 
$counts = array(
    'Products' => 'catalog/product',
    'CMS Blocks' => 'cms/block',
    'CMS Pages' => 'cms/page',
    'Customers' => 'customer/customer',
    'Customer Groups' => 'customer/group',
    'Customer Segments' => 'enterprise_customersegment/segment',
    'Newsletter Subscribers' => 'newsletter/subscriber',
    'Wishlists' => 'wishlist/wishlist',
    'Catalog Price Rules' => 'catalogrule/rule',
    'Shopping Cart Price Rules' => 'salesrule/rule',
    'Shopping Cart Price Rule Coupons' => 'salesrule/coupon',
    'Rule-Based Product Relations' => 'enterprise_targetrule/rule',
    'Quotes' => 'sales/quote',
    'Orders' => 'sales/order',
    'Invoices' => 'sales/order_invoice',
    'Shipments' => 'sales/order_shipment',
);
?>
<table class="summary">
    <?php foreach ($counts as $title => $alias): ?>
    <?php
        $model = Mage::getModel($alias);
        if (!$model) {
            continue;
        }
    ?>
    <tr>
        <th><?php echo $title; ?></th>
        <td><?php echo number_format($model->getCollection()->getSize()); ?></td>
    </tr>
    <?php endforeach; ?>
</table>
<h2 id="stores">Stores</h2>
<table class="summary">
    <thead>
        <tr>
            <th>Website</th>
            <th>Store Group</th>
            <th>Store View</th>
        </tr>
    </thead>
    <tbody>
    <?php $printedWebsite = false; ?>
    <?php $printedStoreGroup = false; ?>
    <?php foreach (getStores() as $webSiteId => $webSiteData): ?>

        <?php if (count($webSiteData['storeGroups']) == 0): ?>

            <tr>
                <?php if (!$printedWebsite): ?>
                    <td rowspan="<?php echo $webSiteData['count'] ?>"><?php echo $webSiteData['object']->getName() ?> (<?php echo $webSiteData['object']->getCode() ?>)</td>
                <?php endif ?>

                <td colspan="2">&nbsp;</td>
            </tr>

            <?php $printedWebsite = false; ?>
            <?php continue ?>
        <?php endif ?>

        <?php foreach ($webSiteData['storeGroups'] as $storeGroupId => $storeGroupData): ?>
            <?php if (count($storeGroupData['stores']) == 0): ?>
                <tr>
                    <?php if (!$printedWebsite): ?>
                        <td rowspan="<?php echo $webSiteData['count'] ?>"><?php echo $webSiteData['object']->getName() ?> (<?php echo $webSiteData['object']->getCode() ?>)</td>
                        <?php $printedWebsite = true; ?>
                    <?php endif ?>

                    <?php if (!$printedStoreGroup): ?>
                    <td rowspan="<?php echo $storeGroupData['count'] ?>"><?php echo $storeGroupData['object']->getName() ?></td>
                    <?php endif ?>

                    <td>&nbsp;</td>
                </tr>
                <?php $printedStoreGroup = false; ?>
                <?php continue ?>
            <?php endif ?>

            <?php foreach ($storeGroupData['stores'] as $storeId => $storeData): ?>
                <tr>
                    <?php if (!$printedWebsite): ?>
                        <td rowspan="<?php echo $webSiteData['count'] ?>"><?php echo $webSiteData['object']->getName() ?> (<?php echo $webSiteData['object']->getCode() ?>)</td>
                        <?php $printedWebsite = true; ?>
                    <?php endif ?>

                    <?php if (!$printedStoreGroup): ?>
                    <td rowspan="<?php echo $storeGroupData['count'] ?>"><?php echo $storeGroupData['object']->getName() ?></td>
                        <?php $printedStoreGroup = true; ?>
                    <?php endif ?>

                    <td><?php echo $storeData['object']->getName(); ?> (<?php echo $storeData['object']->getCode() ?>)</td>
                </tr>
            <?php endforeach; ?>
            <?php $printedStoreGroup = false; ?>
        <?php endforeach; ?>
        <?php $printedWebsite = false; ?>
    <?php endforeach; ?>
    </tbody>
</table>
<h2 id="cache">Cache</h2>
<table class="summary">
    <?php foreach (getCache() as $name => $type): ?>
    <tr>
        <th><?php echo $name ?></th>
        <td><?php echo $type ?></td>
    </tr>
    <?php endforeach ?>
</table>
<h2 id="products">Products</h2>
<table class="summary">
    <?php foreach (Mage::getModel('catalog/product_type')->getTypes() as $type_id => $type_data): ?>
    <tr>
        <th><?php echo $type_data['label']; ?></th>
        <td><?php echo number_format(Mage::getModel('catalog/product')->getCollection()->addAttributeToFilter('type_id', $type_id)->getSize()); ?></td>
    </tr>
    <?php endforeach; ?>
</table>
<?php $listobservers = getAllObservers() ?>
<?php if(count($listobservers)): ?>
<h2 id="observers">Observers</h2>
<table class="summary">
    <?php foreach ($listobservers as $name => $methods): ?>
        <tr>
            <th><?php echo $name ?></th>
            <td class="observer">
            <?php foreach ($methods as $method): ?>
                <?php echo $method ?><br />
            <?php endforeach ?>
            </td>
        </tr>
    <?php endforeach; ?>
</table>
<?php endif ?>
<h2 id="modules">Modules</h2>
<ul>
    <li><a href="?methods=true">Include overridden methods.</a></li>
</ul>
<h3>Summary</h3>
<table class="summary">
    <tr>
        <th>Community modules</th>
        <td><?php echo count($codePools['community']); ?></td>
    </tr>
    <tr>
        <th>Local modules</th>
        <td><?php echo count($codePools['local']); ?></td>
    </tr>
    <tr>
        <th>Block rewrites</th>
        <td><?php echo count($systemRewrites['blocks']); ?></td>
    </tr>
    <tr>
        <th>Controller rewrites</th>
        <td><?php echo count($systemRewrites['controllers']); ?></td>
    </tr>
    <tr>
        <th>Helper rewrites</th>
        <td><?php echo count($systemRewrites['helpers']); ?></td>
    </tr>
    <tr>
        <th>Model rewrites</th>
        <td><?php echo count($systemRewrites['models']); ?></td>
    </tr>
</table>

<?php foreach ($codePools as $codePoolType => $modules): ?>
    <?php if ($codePoolType == 'core') continue; ?>
    <h3><?php echo ucwords($codePoolType); ?> code pool</h3>
    <?php if (count($modules)): ?>
        <?php foreach ($modules as $module): ?>
        <?php $moduleName = $module['name'] ?>
        <div class="module">
            <h4><?php echo $moduleName; ?></h4>
            <?php if (!empty($module['depends'])): ?>
            <p>Requires:</p>
            <ul>
                <?php foreach ($module['depends'] as $name => $value): ?>
                <li><?php echo $name ?></li>
                <?php endforeach ?>
            </ul>
            <?php endif ?>
            <?php if (isset($dependancies[$moduleName])): ?>
            <p>Required by:</p>
            <ul>
                <?php foreach ($dependancies[$moduleName] as $name): ?>
                <li><?php echo $name ?></li>
                <?php endforeach ?>
            </ul>
            <?php endif ?>
            <?php foreach ($moduleRewrites as $rewriteType => $rewrites): ?>
                <?php if (isset($rewrites[$moduleName]) && count($rewrites[$moduleName])): ?>
                    <p class="rewrite">Rewritten <?php echo ucwords($rewriteType); ?>:</p>
                    <ul>
                    <?php foreach ($rewrites[$moduleName] as $rewrite): ?>
                        <li>
                            <?php echo $rewrite['alias']; ?> =&gt; <?php echo $rewrite['class']; ?>
                            <?php if (isset($_GET['methods'])): ?>
                                <?php $methods = getOverridenMethods($rewrite['class']); ?>
                                <?php if (count($methods)): ?>
                                <ul>
                                    <?php foreach ($methods as $method): ?>
                                    <li><?php echo $method; ?></li>
                                    <?php endforeach; ?>
                                </ul>
                                <?php endif; ?>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    <?php else: ?>
        <p>No modules found</p>
    <?php endif; ?>
<?php endforeach; ?>
</body>
</html>
