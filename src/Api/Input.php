<?php

namespace App\Api;

use App\Entity\Unit;
use Symfony\Component\HttpFoundation\Request;

/** Odczyt i walidacja treści JSON żądania; błędy pól zbiera i zgłasza razem jako 422. */
final class Input
{
    private const MAX_BODY = 65536;

    /** @var list<array{field: string, message: string}> */
    private array $errors = [];

    /** @param array<string, mixed> $body */
    private function __construct(private readonly array $body) {}

    public static function fromRequest(Request $request, bool $bodyOptional = false): self
    {
        $raw = $request->getContent();
        if ($raw === '' && $bodyOptional) {
            return new self([]);
        }

        if (strlen($raw) > self::MAX_BODY || !str_starts_with(strtolower((string) $request->headers->get('Content-Type')), 'application/json')) {
            throw ApiProblem::badRequest();
        }

        try {
            $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ApiProblem::badRequest();
        }
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw ApiProblem::badRequest();
        }

        return new self($data);
    }

    public function has(string $field): bool
    {
        return array_key_exists($field, $this->body);
    }

    public function isEmpty(): bool
    {
        return $this->body === [];
    }

    /** @param list<string> $allowed */
    public function rejectUnknown(array $allowed): void
    {
        foreach (array_keys($this->body) as $field) {
            if (!in_array($field, $allowed, true)) {
                $this->error((string) $field, 'Nieznane pole');
            }
        }
    }

    public function error(string $field, string $message): void
    {
        $this->errors[] = ['field' => $field, 'message' => $message];
    }

    public function finish(): void
    {
        if ($this->errors !== []) {
            throw ApiProblem::validation($this->errors);
        }
    }

    public function requireNonEmpty(): void
    {
        if ($this->isEmpty()) {
            $this->error('body', 'Treść musi zawierać co najmniej jedno pole');
        }
    }

    public function string(string $field, string $label, int $max, bool $required = false, bool $nullable = false): ?string
    {
        if (!$this->has($field)) {
            if ($required) {
                $this->error($field, $label . ' jest wymagana');
            }

            return null;
        }

        $value = $this->body[$field];
        if ($value === null && $nullable) {
            return null;
        }
        if (!is_string($value)) {
            $this->error($field, $label . ' musi być tekstem');

            return null;
        }

        $value = trim($value);
        if ($value === '' && ($required || !$nullable)) {
            $this->error($field, $label . ' jest wymagana');

            return null;
        }
        if (preg_match('/[\p{C}]/u', $value) === 1) {
            $this->error($field, $label . ' zawiera niedozwolone znaki');

            return null;
        }
        if (mb_strlen($value) > $max) {
            $this->error($field, sprintf('%s może mieć najwyżej %d znaków', $label, $max));

            return null;
        }

        return $value === '' ? null : $value;
    }

    public function ean(): ?string
    {
        if (!$this->has('ean')) {
            $this->error('ean', 'Kod EAN jest wymagany');

            return null;
        }
        $ean = $this->body['ean'];
        if (!is_string($ean) || preg_match('/^[0-9]{1,14}$/', $ean) !== 1) {
            $this->error('ean', 'Kod EAN musi składać się z 1–14 cyfr');

            return null;
        }

        return $ean;
    }

    public function unit(): ?Unit
    {
        $value = $this->body['unit'] ?? null;
        if ($value === null) {
            return null;
        }
        $unit = is_string($value) ? Unit::tryFrom($value) : null;
        if ($unit === null) {
            $this->error('unit', 'Nieznana jednostka (dozwolone: ' . implode(', ', array_map(fn(Unit $u) => $u->value, Unit::cases())) . ')');
        }

        return $unit;
    }

    /** Liczba dodatnia z najwyżej 3 miejscami po przecinku. */
    public function amount(string $field, string $label, float $max): ?float
    {
        $value = $this->body[$field] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_int($value) && !is_float($value)) {
            $this->error($field, $label . ' musi być liczbą');

            return null;
        }
        $value = (float) $value;
        if ($value <= 0 || $value > $max) {
            $this->error($field, sprintf('%s musi być większa od 0 i nie większa niż %s', $label, rtrim(rtrim(number_format($max, 3, '.', ''), '0'), '.')));

            return null;
        }
        if (abs(round($value, 3) - $value) > 1e-9) {
            $this->error($field, $label . ' może mieć najwyżej 3 miejsca po przecinku');

            return null;
        }

        return round($value, 3);
    }

    public function integer(string $field, string $label, int $min, int $max, ?int $default = null): ?int
    {
        if (!$this->has($field)) {
            return $default;
        }
        $value = $this->body[$field];
        if (!is_int($value) || $value < $min || $value > $max) {
            $this->error($field, sprintf('%s musi być liczbą całkowitą od %d do %d', $label, $min, $max));

            return $default;
        }

        return $value;
    }

    public function nullableInteger(string $field, string $label, int $min, int $max): ?int
    {
        if (($this->body[$field] ?? null) === null) {
            return null;
        }

        return $this->integer($field, $label, $min, $max);
    }

    /** Data kalendarzowa YYYY-MM-DD; `null` jest poprawną wartością. */
    public function date(string $field, string $label): ?string
    {
        $value = $this->body[$field] ?? null;
        if ($value === null) {
            return null;
        }
        $date = is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
            ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value)
            : false;
        if ($date === false || $date->format('Y-m-d') !== $value) {
            $this->error($field, $label . ' musi być datą w formacie RRRR-MM-DD');

            return null;
        }

        return $value;
    }

    /** @return list<int>|null */
    public function idList(string $field, string $label): ?array
    {
        if (!$this->has($field)) {
            return null;
        }
        $value = $this->body[$field];
        $valid = is_array($value) && array_is_list($value) && count($value) <= 50;
        if ($valid) {
            foreach ($value as $id) {
                $valid = $valid && is_int($id) && $id >= 1;
            }
        }
        if (!$valid || count(array_unique($value)) !== count($value)) {
            $this->error($field, $label . ' muszą być listą unikalnych dodatnich liczb całkowitych (najwyżej 50)');

            return null;
        }

        return $value;
    }
}
