<?php

$options = getopt('', ['path:']);

require_once $options['path'].'/vendor/autoload.php';

$dir   = new \RecursiveDirectoryIterator($options['path'].'/app/');
$list  = new \RecursiveIteratorIterator($dir);
$files = new \RegexIterator($list, '/.+\.php$/');

foreach ( $files as $file )
{
    require_once $file;
}

$classes = [
    'service' => [],
    'controller' => [],
];

foreach ( get_declared_classes() as $class )
{
    if ( strpos($class, '\\Services\\') !== false )
    {
        array_push($classes['service'], $class);
    }
    if ( strpos($class, '\\Controllers\\') !== false )
    {
        array_push($classes['controller'], $class);
    }
}

foreach ( $classes['service'] as $class )
{
    foreach ( ['getArrCallbackLists', 'getArrLoaders'] as $method )
    {
        $array = $class::$method();

        foreach ( $array as $key => $value )
        {
            if ( is_object($value) && $value instanceof Closure )
            {
                $resolver = $value;
                $params   = (new \ReflectionFunction($resolver))->getParameters();
                $value    = array_keys($params);
            }
            else
            {
                $resolver = array_pop($value);
            }
            $resolverCode = ' => ';
            sort($value);
            $resolverCode .= 'function (';
            foreach ( $value as $i => $dep )
            {
                $camelCase = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $dep))));
                $params = (new \ReflectionFunction($resolver))->getParameters();
                $resolverCode .= '$'.$camelCase;

                foreach ( $params as $param )
                {
                    if ( $param->name === $camelCase && $param->isDefaultValueAvailable() )
                    {
                        if ( $param->getDefaultValue() === '' )
                        {
                            $resolverCode .= '=\'\'';
                        }
                        else
                        {
                            throw new \Exception;
                        }
                    }
                }

                if ( last($value) !== $dep )
                {
                    $resolverCode .= ', ';
                }
            }

            $resolverCode .= ') {'.PHP_EOL.PHP_EOL;
            $resolverCode .= getResolverCode($resolver).str_repeat('    ', 3).'},'.PHP_EOL;

            $array[$key] = $resolverCode;
        }
        writeKeyedArrayCode($class, $method, $array);
    }

    $array = $class::getArrBindNames();
    foreach ( $array as $key => $value )
    {
        $array[$key] = PHP_EOL.str_repeat('    ', 4).'=> \''.addslashes($value).'\','.PHP_EOL;
    }
    writeKeyedArrayCode($class, 'getArrBindNames', $array);

    $array = $class::getArrPromiseLists();
    foreach ( $array as $key => $value )
    {
        sort($value);
        $array[$key] = PHP_EOL.str_repeat('    ', 4).'=> [\''.implode('\', \'', $value).'\'],'.PHP_EOL;
    }
    writeKeyedArrayCode($class, 'getArrPromiseLists', $array);

    $array = $class::getArrTraits();

    foreach ( $array as $key => $value )
    {
        $segs        = explode('\\', $value);
        $array[$key] = str_repeat('    ', 3).end($segs).'::class,'.PHP_EOL;
    }

    sort($array, SORT_NATURAL);
    writeArrayCode($class, 'getArrTraits', $array);
}

function writeKeyedArrayCode($class, $method, $array)
{
    ksort($array, SORT_NATURAL);

    foreach ( $array as $key => $value )
    {
        $array[$key] = str_repeat('    ', 3).'\''.$key.'\''.$value;

        if ( array_keys($array)[count($array)-1] !== $key )
        {
            $array[$key] = $array[$key].PHP_EOL;
        }
    }

    writeArrayCode($class, $method, $array);
}

function writeArrayCode($class, $method, $array)
{
    if ( empty($array) )
    {
        $array = [str_repeat('    ', 2).'return [];'.PHP_EOL];
    }
    else
    {
        $array = array_values($array);
        array_unshift($array, str_repeat('    ', 2).'return ['.PHP_EOL);
        array_push($array, str_repeat('    ', 2).'];'.PHP_EOL);
    }

    $method    = (new \ReflectionClass($class))->getMethod($method)->getClosure();
    $func      = (new \ReflectionFunction($method));
    $file      = $func->getFileName();
    $lines     = file($file);
    $startLine = $func->getStartLine() + 1;
    $endLine   = $func->getEndLine() - 1;
    $length    = $endLine - $startLine;

    array_splice($lines, $startLine, $length, [implode('', $array)]);
    $content   = implode('', $lines);
    $fOpen     = fopen($file, 'w');

    fwrite($fOpen, $content);
    fclose($fOpen);
}

function getResolverCode($resolver)
{
    $ref   = (new \ReflectionFunction($resolver));
    $lines = file($ref->getFileName());
    $start = $ref->getStartLine()+1;
    $end   = $ref->getEndLine()-1;
    $lines = array_splice($lines, $start, $end-$start);

    return implode('', $lines);
}
