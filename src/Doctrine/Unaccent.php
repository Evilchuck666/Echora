<?php

namespace App\Doctrine;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * "UNACCENT" "(" StringPrimary ")": PostgreSQL's unaccent(), which removes accents ("Motörhead" → "Motorhead").
 *
 * Needs the unaccent extension, enabled by a migration.
 */
final class Unaccent extends FunctionNode
{
    private Node $string;

    public function getSql(SqlWalker $sqlWalker): string
    {
        return \sprintf('unaccent(%s)', $sqlWalker->walkSimpleArithmeticExpression($this->string));
    }

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->string = $parser->StringPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }
}
