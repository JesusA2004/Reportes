<?php

use App\Services\EmployeeNameCanonicalizer;

/**
 * FASE 4 del cierre de Reportes — fuzzy conservador, NO agresivo.
 *
 * Regla vigente (EmployeeNameCanonicalizer::buildCanonicalMap): dos nombres se fusionan
 * SOLO si levenshtein(normA, normB) <= 2 Y comparten >= 3 tokens de >= 3 caracteres.
 * Casos positivos y negativos explícitos pedidos en el cierre del proyecto.
 */
it('merges a real typo variant (DOMIGUEZ vs DOMINGUEZ) when the rest of the name matches', function () {
    $canonicalizer = new EmployeeNameCanonicalizer();

    $map = $canonicalizer->buildCanonicalMap([
        'MARGARITA JAZMIN NOLASCO DOMINGUEZ',
        'MARGARITA JAZMIN NOLASCO DOMIGUEZ', // typo real observado en producción
    ], payrollNames: ['MARGARITA JAZMIN NOLASCO DOMINGUEZ']);

    $keyA = $canonicalizer->normalize('MARGARITA JAZMIN NOLASCO DOMINGUEZ');
    $keyB = $canonicalizer->normalize('MARGARITA JAZMIN NOLASCO DOMIGUEZ');

    expect($map[$keyA])->toBe($map[$keyB]);
    // El nombre de nómina (fuente más autoritativa) gana como canónico.
    expect($map[$keyA])->toBe($keyA);
});

it('does NOT merge two different real employees with a similar name (JR suffix)', function () {
    $canonicalizer = new EmployeeNameCanonicalizer();

    $map = $canonicalizer->buildCanonicalMap([
        'JUAN PEREZ LOPEZ',
        'JUAN PEREZ LOPEZ JR',
    ]);

    $keyA = $canonicalizer->normalize('JUAN PEREZ LOPEZ');
    $keyB = $canonicalizer->normalize('JUAN PEREZ LOPEZ JR');

    expect($map[$keyA])->not->toBe($map[$keyB]);
});

it('does NOT merge two clearly different names sharing only a common surname', function () {
    $canonicalizer = new EmployeeNameCanonicalizer();

    $map = $canonicalizer->buildCanonicalMap([
        'MARIA FERNANDA HERNANDEZ LOPEZ',
        'CARLOS ALBERTO HERNANDEZ RUIZ',
    ]);

    $keyA = $canonicalizer->normalize('MARIA FERNANDA HERNANDEZ LOPEZ');
    $keyB = $canonicalizer->normalize('CARLOS ALBERTO HERNANDEZ RUIZ');

    expect($map[$keyA])->not->toBe($map[$keyB]);
});

it('explainMerge reports the exact reason a pair does or does not merge (auditability)', function () {
    $canonicalizer = new EmployeeNameCanonicalizer();

    $typo = $canonicalizer->explainMerge(
        $canonicalizer->normalize('MARGARITA JAZMIN NOLASCO DOMINGUEZ'),
        $canonicalizer->normalize('MARGARITA JAZMIN NOLASCO DOMIGUEZ'),
    );
    expect($typo['merges'])->toBeTrue();
    expect($typo['levenshtein'])->toBeLessThanOrEqual(2);
    expect($typo['common_tokens'])->toBeGreaterThanOrEqual(3);

    $different = $canonicalizer->explainMerge(
        $canonicalizer->normalize('JUAN PEREZ LOPEZ'),
        $canonicalizer->normalize('JUAN PEREZ LOPEZ JR'),
    );
    expect($different['merges'])->toBeFalse();
});
