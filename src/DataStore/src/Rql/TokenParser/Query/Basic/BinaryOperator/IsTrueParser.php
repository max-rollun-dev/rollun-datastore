<?php

namespace rollun\datastore\Rql\TokenParser\Query\Basic\BinaryOperator;

use rollun\datastore\Rql\Node\BinaryNode\IsTrueNode;

class IsTrueParser extends BinaryTokenParserAbstract
{
    public function getOperatorName()
    {
        return 'isTrue';
    }

    protected function createNode(string $field)
    {
        return new IsTrueNode($field);
    }
}
