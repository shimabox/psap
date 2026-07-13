<?php

declare(strict_types=1);

namespace Psap\Analyzer;

enum DependencyKind: string
{
    case Extends = 'extends';
    case Implements = 'implements';
    case TraitUse = 'trait_use';
    case PropertyType = 'property_type';
    case ParameterType = 'parameter_type';
    case ReturnType = 'return_type';
    case New = 'new';
    case StaticCall = 'static_call';
    case StaticProperty = 'static_property';
    case ClassConstant = 'class_constant';
    case Instanceof = 'instanceof';
    case Catch = 'catch';
    case Attribute = 'attribute';
    case DocblockVar = 'docblock_var';
    case DocblockParam = 'docblock_param';
    case DocblockReturn = 'docblock_return';
    case DocblockThrows = 'docblock_throws';
}
