<?php

namespace Caixingyue\LaravelStarLog\Query;

/**
 * Verify a small predicate grammar before associating placeholders with columns.
 */
final class SqlWhereBindingMapper
{
    private const MAX_CONDITION_LENGTH = 65536;

    private const MAX_PARSE_STEPS = 1000;

    private const MAX_GROUP_DEPTH = 64;

    private const IDENTIFIER_SEGMENT = '(?:`[^`.]+`|"[^".]+"|\[[^.\]]+\]|[A-Za-z_][A-Za-z0-9_$]*)';

    private const IDENTIFIER = self::IDENTIFIER_SEGMENT . '(?:\s*\.\s*' . self::IDENTIFIER_SEGMENT . ')*';

    private const VALUE = "(?:\\?|:[A-Za-z_][A-Za-z0-9_]*|'(?:''|[^'\\\\])*'|[+-]?[0-9]+(?:\\.[0-9]+)?|null|true|false)";

    /**
     * Map WHERE clause placeholders to their corresponding columns.
     *
     * @param  list<string>  $qualifiers
     * @return array<int|string, string|null>|null
     */
    public function map(string $conditions, array $qualifiers): ?array
    {
        $predicates = $this->parsePredicates($conditions);

        if ($predicates === null) {
            return null;
        }

        $fields = [];
        $named = null;

        foreach ($predicates as $predicate) {
            $column = $this->resolveColumn($predicate['column'], $qualifiers);
            preg_match_all(/** @lang text */ "~'(?:''|[^'\\\\])*'(*SKIP)(*F)|\\?|:[A-Za-z_][A-Za-z0-9_]*~", $predicate['operand'], $placeholders);

            foreach ($placeholders[0] as $placeholder) {
                $isNamed = $placeholder !== '?';

                if ($named !== null && $named !== $isNamed) {
                    return null;
                }

                $named = $isNamed;

                if (! $isNamed) {
                    $fields[] = $column;

                    continue;
                }

                $name = substr($placeholder, 1);

                // A parameter name used for different columns has no reliable column mapping.
                if (array_key_exists($name, $fields) && $fields[$name] !== $column) {
                    $fields[$name] = null;
                } else {
                    $fields[$name] = $column;
                }
            }
        }

        return $fields;
    }

    /**
     * Validate WHERE conditions and extract their predicates.
     *
     * @return list<array{column: string, operand: string}>|null
     */
    private function parsePredicates(string $conditions): ?array
    {
        $length = strlen($conditions);

        if ($length > self::MAX_CONDITION_LENGTH) {
            return null;
        }

        $pattern = $this->predicatePattern();
        $position = 0;
        $expectOperand = true;
        $depth = 0;
        $predicates = [];
        $visited = 0;

        while ($position < $length) {
            if (++$visited > self::MAX_PARSE_STEPS) {
                return null;
            }

            if (preg_match('/\G\s+/', $conditions, $space, 0, $position) === 1) {
                $position += strlen($space[0]);

                continue;
            }

            if ($expectOperand && $conditions[$position] === '(') {
                if (++$depth > self::MAX_GROUP_DEPTH) {
                    return null;
                }

                $position++;

                continue;
            }

            if (! $expectOperand && $conditions[$position] === ')' && $depth > 0) {
                $depth--;
                $position++;

                continue;
            }

            if (! $expectOperand && preg_match('/\G(?:and|or)\b/i', $conditions, $operator, 0, $position) === 1) {
                $expectOperand = true;
                $position += strlen($operator[0]);

                continue;
            }

            if (! $expectOperand || preg_match($pattern, $conditions, $predicate, 0, $position) !== 1) {
                return null;
            }

            $predicates[] = ['column' => $predicate['column'], 'operand' => $predicate['operand']];
            $position += strlen($predicate[0]);
            $expectOperand = false;
        }

        return $expectOperand || $depth !== 0 ? null : $predicates;
    }

    /**
     * Build the matching pattern for supported SQL predicates.
     */
    private function predicatePattern(): string
    {
        return sprintf(/** @lang text */ <<<'REGEX'
        ~
            (?<column>%1$s) \s*
            (?<operand>
                (?:not \s+)? between \s+ %2$s \s+ and \s+ %2$s
                | (?:not \s+)? in \s* \( \s* %2$s (?:\s*,\s* %2$s)* \s* \)
                | is \s+ (?:not \s+)? null
                | (?:<=|>=|<>|!=|=|<|>|(?:not \s+)? like\b) \s* %2$s
            )
        ~ixA
        REGEX, self::IDENTIFIER, self::VALUE);
    }

    /**
     * Resolve a column name after verifying its table or alias qualifier.
     *
     * @param  list<string>  $qualifiers
     */
    private function resolveColumn(string $identifier, array $qualifiers): ?string
    {
        $segments = explode('.', SqlStatement::normalizeIdentifier($identifier));
        $column = array_pop($segments);

        return $segments === [] || in_array(implode('.', $segments), $qualifiers, true) ? $column : null;
    }
}
