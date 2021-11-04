<?php


namespace zcstation;


class Test
{
    public static function say($message = "Hello,world!")
    {
        echo $message . PHP_EOL;
    }

    public static function bye()
    {
        echo "Bye!" . PHP_EOL;
    }
}