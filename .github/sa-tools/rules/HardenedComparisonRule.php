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
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Flags comparing an HMAC against an expected value with a non-constant-time
 * operator ("===", "!==", "==" or "!="). Signatures and message authentication
 * codes must be compared with hash_equals() to avoid a timing side channel.
 *
 * Only the inline form, where hash_hmac() is an operand of the comparison
 * (optionally wrapped in an encoder such as base64_encode()), is detected; the
 * assign-then-compare idiom is out of reach of a syntactic rule.
 *
 * @see Symfony\Component\Mailer\Bridge\Mailchimp\Webhook\MailchimpRequestParser
 *
 * @implements Rule<Expr\BinaryOp>
 */
class HardenedComparisonRule implements Rule
{
    private const ENCODERS = ['base64_encode', 'bin2hex', 'strtoupper', 'strtolower', 'trim', 'rtrim'];
    private const MAX_DEPTH = 3;

    public function getNodeType(): string
    {
        return Expr\BinaryOp::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node instanceof Expr\BinaryOp\Identical
            && !$node instanceof Expr\BinaryOp\NotIdentical
            && !$node instanceof Expr\BinaryOp\Equal
            && !$node instanceof Expr\BinaryOp\NotEqual
        ) {
            return [];
        }

        if (!$this->isMac($node->left) && !$this->isMac($node->right)) {
            return [];
        }

        return [
            RuleErrorBuilder::message('Comparing an HMAC with "===", "!==", "==" or "!=" is not constant-time; use hash_equals() instead.')
                ->identifier('symfony.nonConstantTimeHmacComparison')
                ->build(),
        ];
    }

    private function isMac(Expr $expr, int $depth = 0): bool
    {
        if (!$expr instanceof Expr\FuncCall || !$expr->name instanceof Node\Name) {
            return false;
        }

        $name = $expr->name->toLowerString();

        if ('hash_hmac' === $name) {
            return true;
        }

        if ($depth < self::MAX_DEPTH && \in_array($name, self::ENCODERS, true)) {
            $args = $expr->getArgs();
            if (isset($args[0])) {
                return $this->isMac($args[0]->value, $depth + 1);
            }
        }

        return false;
    }
}
