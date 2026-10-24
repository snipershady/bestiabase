<?php

declare(strict_types=1);

namespace App\Blast\Result;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Filtri della tabella risultati, dalla query string (`?top=5&cutoff=identity&value=95`).
 * Sostituiscono "Filter" e "Parse again" del legacy, che riscrivevano file: qui sono SQL.
 */
final readonly class HitFilter
{
    /** Righe per query del legacy: "All", "Top score", "Top 5", "Top 10". */
    public const array TOP_CHOICES = [0, 1, 5, 10];

    public const int QUERIES_PER_PAGE = 20;

    public function __construct(
        /** Hit mostrati per query; 0 = tutti. Default 10 come la tabella legacy. */
        #[Assert\Choice(choices: self::TOP_CHOICES)]
        public int $top = 10,
        public CutoffType $cutoff = CutoffType::None,
        #[Assert\PositiveOrZero]
        public float $value = 0.0,
        public HitSort $sort = HitSort::Rank,
        #[Assert\Positive]
        public int $page = 1,
    ) {
    }

    /**
     * Prima e ultima posizione delle query mostrate in questa pagina.
     *
     * @return array{int, int}
     */
    public function queryRange(): array
    {
        $first = ($this->page - 1) * self::QUERIES_PER_PAGE + 1;

        return [$first, $first + self::QUERIES_PER_PAGE - 1];
    }

    /**
     * Parametri per i link di paginazione e ordinamento, senza i valori di default.
     *
     * @return array<string, int|float|string>
     */
    public function toQuery(): array
    {
        return array_filter([
            'top' => $this->top === 10 ? null : $this->top,
            'cutoff' => $this->cutoff === CutoffType::None ? null : $this->cutoff->value,
            'value' => $this->cutoff === CutoffType::None ? null : $this->value,
            'sort' => $this->sort === HitSort::Rank ? null : $this->sort->value,
        ], static fn (int|float|string|null $value): bool => $value !== null);
    }
}
