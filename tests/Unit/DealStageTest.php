<?php

use App\Modules\CRM\Enums\DealStage;
use App\Modules\CRM\Enums\LeadType;

it('lets a person move a deal only where the rules say, and never to a stage a document sets', function () {
    $moves = fn (DealStage $from) => array_map(fn (DealStage $s) => $s->value, $from->manualMoves());

    expect($moves(DealStage::Discovery))->toBe(['negotiation', 'lost'])
        ->and($moves(DealStage::Negotiation))->toBe(['discovery', 'lost'])
        ->and($moves(DealStage::Quotation))->toBe(['lost'])                  // a quotation is out: it can only be lost
        ->and($moves(DealStage::Lost))->toBe(['discovery', 'negotiation'])   // re-opened (which takes its own permission)
        ->and($moves(DealStage::Won))->toBe([]);

    // nobody can choose Quotation or Won
    foreach (DealStage::cases() as $from) {
        expect($from->canMoveTo(DealStage::Quotation))->toBeFalse()->and($from->canMoveTo(DealStage::Won))->toBeFalse();
    }

    expect(DealStage::Quotation->isSetBySystem())->toBeTrue()->and(DealStage::Won->isSetBySystem())->toBeTrue()
        ->and(DealStage::Discovery->isSetBySystem())->toBeFalse()->and(DealStage::Lost->isSetBySystem())->toBeFalse();
});

it('knows which stages are still open and the probability a deal starts with in each', function () {
    expect(array_map(fn (DealStage $s) => $s->isOpen(), DealStage::cases()))->toBe([true, true, true, false, false])
        ->and(array_map(fn (DealStage $s) => $s->defaultProbability(), DealStage::cases()))->toBe([10, 50, 70, 100, 0])
        ->and(DealStage::values())->toBe(['discovery', 'negotiation', 'quotation', 'won', 'lost']);
});

it('labels the two kinds of lead', function () {
    expect(LeadType::from('existing')->label())->toBe('Existing customer')->and(LeadType::New->label())->toBe('New lead')
        ->and(LeadType::tryFrom('prospect'))->toBeNull();
});
