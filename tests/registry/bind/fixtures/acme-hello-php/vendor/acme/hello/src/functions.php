<?php declare(strict_types=1);

namespace Acme\Hello;

function greet(string $name): string
{
    return Greeter::greet($name);
}
