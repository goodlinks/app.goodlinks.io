<?php

namespace App\Helper;

use Twig_Loader_Filesystem;
use Twig_Loader_String;
use Twig_SimpleFilter;
use Twig_Environment;

class TwigHelper
{
    public static function twig()
    {
        $loader = new Twig_Loader_Filesystem(dirname(dirname(dirname(__FILE__))) . '/resources/views/');
        $twig = new Twig_Environment($loader, array(
            'debug' => true,
            'cache' => dirname(dirname(dirname(__FILE__))) . '/storage/template_cache',
        ));

        // Debug
        $twig->addExtension(new \Twig_Extension_Debug());

        // String Loader
        $twig->addExtension(new \Twig_Extension_StringLoader());

        return $twig;
    }
}