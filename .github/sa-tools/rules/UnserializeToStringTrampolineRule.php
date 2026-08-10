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
use PhpParser\Node\Expr;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\StringType;
use PHPStan\Type\TypeCombinator;

/**
 * Flags __unserialize() implementations that assign a value coming from the
 * unserialized payload into a string-typed property without first rejecting
 * object values. An object exposing __toString() would be coerced to string
 * during unserialize(), turning the property assignment into a "trampoline"
 * that fires the gadget. The accepted fix rejects \Stringable values up front.
 *
 * @see Symfony\Component\Routing\Route::__unserialize()
 * @see Symfony\Component\RateLimiter\Policy\TokenBucket::__unserialize()
 *
 * @implements Rule<Node\Stmt\ClassMethod>
 */
class UnserializeToStringTrampolineRule implements Rule
{
    private const TYPE_PROBES = ['is_object', 'is_scalar', 'is_string', 'gettype', 'get_debug_type'];

    public function getNodeType(): string
    {
        return Node\Stmt\ClassMethod::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if ('__unserialize' !== $node->name->toLowerString() || null === $node->stmts) {
            return [];
        }

        if (null === $classReflection = $scope->getClassReflection()) {
            return [];
        }

        $params = $node->getParams();
        if (!isset($params[0]) || !$params[0]->var instanceof Expr\Variable || !\is_string($params[0]->var->name)) {
            return [];
        }
        $dataParam = $params[0]->var->name;

        // A single up-front object/Stringable guard is treated as sufficient,
        // matching how the accepted fixes guard every slot at once.
        if ($this->hasGuard($node->stmts)) {
            return [];
        }

        $finder = new NodeFinder();
        $errors = [];

        foreach ($finder->findInstanceOf($node->stmts, Expr\Assign::class) as $assign) {
            // The right-hand side must read from the unserialized payload.
            if (null === $finder->findFirst($assign->expr, static fn (Node $n): bool => $n instanceof Expr\Variable && $dataParam === $n->name)) {
                continue;
            }

            foreach ($this->targetProperties($assign->var) as $propName) {
                if (!$classReflection->hasNativeProperty($propName)) {
                    continue;
                }

                $propType = $classReflection->getNativeProperty($propName)->getNativeType();
                if (!TypeCombinator::removeNull($propType) instanceof StringType) {
                    continue;
                }

                $errors[] = RuleErrorBuilder::message(\sprintf('Property "$%s" is assigned from the unserialized payload without rejecting \Stringable values first; an object would fire __toString() during unserialize(). Reject object values up front, e.g. with "instanceof \Stringable".', $propName))
                    ->identifier('symfony.unserializeToStringTrampoline')
                    ->line($assign->getStartLine())
                    ->build();
            }
        }

        return $errors;
    }

    /**
     * Property names written by an assignment, covering both "$this->prop = ..."
     * and list destructuring "[$this->a, $this->b] = $data".
     *
     * @return list<string>
     */
    private function targetProperties(Expr $var): array
    {
        if ($var instanceof Expr\PropertyFetch) {
            return $var->name instanceof Node\Identifier ? [$var->name->toString()] : [];
        }

        if ($var instanceof Expr\List_ || $var instanceof Expr\Array_) {
            $props = [];
            foreach ($var->items as $item) {
                if (null !== $item) {
                    $props = array_merge($props, $this->targetProperties($item->value));
                }
            }

            return $props;
        }

        return [];
    }

    /**
     * @param Node\Stmt[] $stmts
     */
    private function hasGuard(array $stmts): bool
    {
        $finder = new NodeFinder();

        if (null !== $finder->findFirst($stmts, static fn (Node $n): bool => $n instanceof Expr\Instanceof_)) {
            return true;
        }

        return null !== $finder->findFirst($stmts, function (Node $n): bool {
            return $n instanceof Expr\FuncCall
                && $n->name instanceof Node\Name
                && \in_array($n->name->toLowerString(), self::TYPE_PROBES, true);
        });
    }
}
