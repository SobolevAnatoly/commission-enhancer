<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInitfe81252e6b50819fc066a314f792ee11
{
    public static $prefixLengthsPsr4 = array (
        'C' => 
        array (
            'Codeable\\CommissionEnhancer\\' => 28,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'Codeable\\CommissionEnhancer\\' => 
        array (
            0 => __DIR__ . '/../..' . '/src',
        ),
    );

    public static $classMap = array (
        'Codeable\\CommissionEnhancer\\Controller\\VendorsCommission' => __DIR__ . '/../..' . '/src/Controller/VendorsCommission.php',
        'Codeable\\CommissionEnhancer\\Init' => __DIR__ . '/../..' . '/src/Init.php',
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInitfe81252e6b50819fc066a314f792ee11::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInitfe81252e6b50819fc066a314f792ee11::$prefixDirsPsr4;
            $loader->classMap = ComposerStaticInitfe81252e6b50819fc066a314f792ee11::$classMap;

        }, null, ClassLoader::class);
    }
}
