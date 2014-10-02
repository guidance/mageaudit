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
                            $class   = trim($matches[1][0]);
                            $extends = trim($matches[2][0]);
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
    $overridenMethods = array();
    //try {
        $class = new ReflectionClass($className);
    //} catch (Exception $e) {
    //    return $overridenMethods;
    //}
    foreach ($class->getMethods() as $method) {
        if ($method->getDeclaringClass()->getName() == $className) {
            $overridenMethods[] = $method->getName();
        }
    }
    sort($overridenMethods);
    return $overridenMethods;
}

function getModules($sorted = true)
{
    $config = Mage::getConfig();
    $configNode = 'modules';
    $modules = $config->getNode($configNode)->asArray();
    $codePools = array();
    foreach ($modules as $package => $config) {
        if (isset($config['codePool'])) {
            $codePool = $config['codePool'];
            $codePools[$codePool][] = $package;
        }
    }
    if ($sorted) {
        foreach (array_keys($codePools) as $codePool) {
            sort($codePools[$codePool]);
        }
    }
    return $codePools;
}

$codePools = getModules();

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
        table.summary th {
            text-align: left;
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
    </style>
</head>
<body>
<h1>Magento Module Audit Report</h1>
<h2>Statistics</h2>
<?php 
$counts = array(
    'Products' => 'catalog/product',
    'Content pages' => 'cms/page',
    'Content blocks' => 'cms/block',
    'Newsletter subscribers' => 'newsletter/subscriber',
    'Customers' => 'customer/customer',
    'Customer groups' => 'customer/group',
    'Sales rules' => 'salesrule/rule',
    'Sales rule coupons' => 'salesrule/coupon',
    'Quotes' => 'sales/quote',
    'Orders' => 'sales/order',
    'Invoices' => 'sales/order_invoice',
    'Shipments' => 'sales/order_shipment',
);
?>
<table class="summary">
    <?php foreach ($counts as $title => $alias): ?>
    <tr>
        <th><?php echo $title; ?></th>
        <td><?php echo number_format(Mage::getModel($alias)->getCollection()->getSize()); ?></td>
    </tr>
    <?php endforeach; ?>
</table>
<h3>Product Breakdown</h3>
<table class="summary">
    <?php foreach (Mage::getModel('catalog/product_type')->getTypes() as $type_id => $type_data): ?>
    <tr>
        <th><?php echo $type_data['label']; ?></th>
        <td><?php echo number_format(Mage::getModel('catalog/product')->getCollection()->addAttributeToFilter('type_id', $type_id)->getSize()); ?></td>
    </tr>
    <?php endforeach; ?>
</table>
<h2>Configuration:</h2>
<ul>
    <li><a href="?methods=true">Include overridden methods.</a></li>
</ul>
<h2>Summary:</h2>
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

<h2>Installed modules:</h2>

<?php foreach ($codePools as $codePoolType => $modules): ?>
    <?php if ($codePoolType == 'core') continue; ?>
    <h3><?php echo ucwords($codePoolType); ?> code pool</h3>
    <?php if (count($modules)): ?>
        <?php foreach ($modules as $moduleName): ?>
        <div class="module">
            <h4><?php echo $moduleName; ?></h4>
            <p class="purpose">Purpose of module:</p>
            <?php foreach ($moduleRewrites as $rewriteType => $rewrites): ?>
                <?php if (count($rewrites[$moduleName])): ?>
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

