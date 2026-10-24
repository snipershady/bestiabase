<?php

declare(strict_types=1);

namespace App\Blast\Search;

/**
 * Codici genetici NCBI offerti dalla UI (`-query_gencode` / `-db_gencode`).
 */
enum GeneticCode: int
{
    case Standard = 1;
    case VertebrateMitochondrial = 2;
    case YeastMitochondrial = 3;
    case MoldProtozoanCoelenterateMitochondrial = 4;
    case InvertebrateMitochondrial = 5;
    case CiliateNuclear = 6;
    case EchinodermMitochondrial = 9;
    case EuplotidNuclear = 10;
    case Bacterial = 11;
    case AlternativeYeastNuclear = 12;
    case AscidianMitochondrial = 13;
    case FlatwormMitochondrial = 14;
    case BlepharismaMacronuclear = 15;

    public function label(): string
    {
        return match ($this) {
            self::Standard => 'Standard',
            self::VertebrateMitochondrial => 'Vertebrate Mitochondrial',
            self::YeastMitochondrial => 'Yeast Mitochondrial',
            self::MoldProtozoanCoelenterateMitochondrial => 'Mold, Protozoan and Coelenterate Mitochondrial',
            self::InvertebrateMitochondrial => 'Invertebrate Mitochondrial',
            self::CiliateNuclear => 'Ciliate Nuclear',
            self::EchinodermMitochondrial => 'Echinoderm Mitochondrial',
            self::EuplotidNuclear => 'Euplotid Nuclear',
            self::Bacterial => 'Bacterial',
            self::AlternativeYeastNuclear => 'Alternative Yeast Nuclear',
            self::AscidianMitochondrial => 'Ascidian Mitochondrial',
            self::FlatwormMitochondrial => 'Flatworm Mitochondrial',
            self::BlepharismaMacronuclear => 'Blepharisma Macronuclear',
        };
    }
}
