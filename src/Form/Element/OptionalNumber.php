<?php declare(strict_types=1);

namespace BulkEdit\Form\Element;

use Laminas\Form\Element\Number;

class OptionalNumber extends Number
{
    use TraitOptionalElement;
}
