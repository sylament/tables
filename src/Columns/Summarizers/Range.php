<?php

declare(strict_types=1);

namespace Sylament\Tables\Columns\Summarizers;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\QueryBuilder;
use Exception;
use Stringable;
use Sylament\Tables\Query\SummaryQuery;

use function Sylament\Support\blank;

class Range extends Summarizer
{
    public const string RANGE_TEMPLATE = '@SylamentTables/partials/Summarizer/range.html.twig';

    protected string|Closure|null $defaultTemplate = self::RANGE_TEMPLATE;

    protected bool|Closure $shouldExcludeNull = true;

    /**
     * Both ends in one statement, as upstream — and never batched, since a range
     * carries two figures where the batch keys one alias to one value.
     *
     * @return array{0: mixed, 1: mixed}
     */
    public function summarize(QueryBuilder $query, string $attribute): array
    {
        if ($this->shouldExcludeNull()) {
            $query = (clone $query)->andWhere("{$attribute} IS NOT NULL");
        }

        $row = SummaryQuery::readRow($query, ["MIN({$attribute}) AS sy_min", "MAX({$attribute}) AS sy_max"]);

        return [$this->toRangeValue($row['sy_min'] ?? null), $this->toRangeValue($row['sy_max'] ?? null)];
    }

    public function excludeNull(bool|Closure $condition = true): static
    {
        $this->shouldExcludeNull = $condition;

        return $this;
    }

    public function shouldExcludeNull(): bool
    {
        return (bool) $this->evaluate($this->shouldExcludeNull);
    }

    /**
     * The two ends as the shortest thing that still tells them apart: the day where
     * they fall on different days, the instant where they do not, one value where
     * they are the same instant.
     */
    public function minimalDateTimeDifference(): static
    {
        $this->formatStateUsing(static function (Range $summarizer, array $state): array {
            if (blank($state[1] ?? null)) {
                unset($state[1]);
            }

            if (blank($state[0] ?? null)) {
                unset($state[0]);
            }

            if (count($state) !== 2) {
                return $state;
            }

            $from = self::toDateTimeImmutable($state[0]);
            $to = self::toDateTimeImmutable($state[1]);

            if (!$from instanceof DateTimeImmutable || !$to instanceof DateTimeImmutable) {
                return $state;
            }

            $dateFormat = $summarizer->getTable()->getDefaultDateDisplayFormat();
            $fromDate = $from->format($dateFormat);
            $toDate = $to->format($dateFormat);

            if ($fromDate !== $toDate) {
                return [$fromDate, $toDate];
            }

            $dateTimeFormat = $summarizer->getTable()->getDefaultDateTimeDisplayFormat();
            $fromDateTime = $from->format($dateTimeFormat);
            $toDateTime = $to->format($dateTimeFormat);

            return $fromDateTime === $toDateTime ? [$fromDateTime] : [$fromDateTime, $toDateTime];
        });

        return $this;
    }

    /**
     * The two ends truncated to the first character that differs, plus one where one
     * end is a prefix of the other — so `Airbag` and `Airport` read as `Airb`–`Airp`.
     */
    public function minimalTextualDifference(): static
    {
        $this->formatStateUsing(static function (array $state): array {
            $originalFrom = self::toText($state[0] ?? null);
            $originalTo = self::toText($state[1] ?? null);

            if (($originalFrom === $originalTo) || $originalTo === '') {
                unset($state[1]);
            }

            if ($originalFrom === '') {
                unset($state[0]);
            }

            if (count($state) !== 2) {
                return $state;
            }

            $originalFromCharacters = mb_str_split($originalFrom);
            $originalToCharacters = mb_str_split($originalTo);

            $from = '';
            $to = '';

            $isFromLonger = count($originalFromCharacters) > count($originalToCharacters);

            $characterIndex = 0;

            foreach ($isFromLonger ? $originalToCharacters : $originalFromCharacters as $characterIndex => $character) {
                $from .= $isFromLonger ? $originalFromCharacters[$characterIndex] : $character;
                $to .= $isFromLonger ? $character : $originalToCharacters[$characterIndex];

                if (mb_strtolower($from) !== mb_strtolower($to)) {
                    break;
                }
            }

            if ($from !== $to) {
                return [$from, $to];
            }

            $characterIndex++;

            if ($isFromLonger) {
                $from .= $originalFromCharacters[$characterIndex] ?? '';
            } else {
                $to .= $originalToCharacters[$characterIndex] ?? '';
            }

            return [$from, $to];
        });

        return $this;
    }

    /**
     * The trimmed text of one end, and the empty string for an end no text can be
     * read off — which is the same answer a blank one gives.
     */
    private static function toText(mixed $value): string
    {
        return is_scalar($value) || $value instanceof Stringable ? mb_trim((string) $value) : '';
    }

    /**
     * A numeric end normalised as every other numeric figure is, anything else — a
     * title, a driver's own date string — left as the driver handed it over.
     */
    private function toRangeValue(mixed $value): mixed
    {
        return is_numeric($value) ? self::toNumber($value) : $value;
    }

    private static function toDateTimeImmutable(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        if (!is_string($value) && !is_int($value)) {
            return null;
        }

        try {
            return new DateTimeImmutable(is_int($value) ? '@' . $value : $value);
        } catch (Exception) {
            // An end the parser cannot read leaves the pair as it is, so a range over a
            // column that is not a date at all still renders rather than failing the page.
            return null;
        }
    }
}
