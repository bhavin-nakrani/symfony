<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Constant\ConstantStringType;

/**
 * @implements Rule<Node\Expr\FuncCall>
 */
class UnserializeMissingAllowedClassesRule implements Rule
{
    public function getNodeType(): string
    {
        return Node\Expr\FuncCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Node\Name
            || 'unserialize' !== strtolower($node->name->name)
            || $this->hasAllowedClassesKey($scope, $node)
        ) {
            return [];
        }

        return [
            RuleErrorBuilder::message('unserialize() calls must specify the "allowed_classes" option.')
                ->identifier('symfony.unserializeMissingAllowedClasses')
                ->build(),
        ];
    }

    private function hasAllowedClassesKey(Scope $scope, Node\Expr\FuncCall $node): bool
    {
        if (null === $optionsArg = $node->getArg('options', 1)) {
            return false;
        }

        if (!$constantArrays = $scope->getType($optionsArg->value)->getConstantArrays()) {
            return false;
        }

        foreach ($constantArrays as $constantArray) {
            $hasAllowedClasses = false;
            foreach ($constantArray->getKeyTypes() as $keyType) {
                if (($keyType instanceof ConstantStringType || $keyType instanceof ConstantIntegerType) && 'allowed_classes' === $keyType->getValue()) {
                    $hasAllowedClasses = true;
                    break;
                }
            }

            if (!$hasAllowedClasses) {
                return false;
            }
        }

        return true;
    }
}
