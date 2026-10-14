<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\AiRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiRulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_rulebook_fills_its_thresholds_from_the_settings(): void
    {
        $this->assertStringContainsString('within 15 days of delivery', AiRules::book('refund')['rules'][2]['description']);
        Setting::create(['key' => 'refund_window_days', 'value' => 30]);
        $this->assertStringContainsString('within 30 days of delivery', AiRules::book('refund')['rules'][2]['description']);
        $this->assertSame(['refund', 'risk', 'reply', 'triage', 'address', 'delivery', 'ask'], array_column(AiRules::books()['books'], 'name'));
        $this->assertSame(array_keys(AiRules::DELIVERY_PLAYBOOK), ['recipient_unavailable', 'wrong_or_incomplete_address', 'refused', 'inaccessible_area', 'cod_cash_not_ready', 'rescheduled_by_customer', 'other']);
    }

    public function test_the_ai_judges_only_its_own_rules_and_a_missing_verdict_is_unclear(): void
    {
        $this->assertSame(['REF-6', 'REF-7', 'REF-8', 'REF-9'], array_column(AiRules::aiRules('refund'), 'id'));
        $this->assertSame(['REF-6', 'REF-7', 'REF-8', 'REF-9'], AiRules::aiSchema('refund')['items']['properties']['id']['enum']);
        $checks = AiRules::merge('refund', [AiRules::result('REF-1', 'pass', 'Captured.')], [['id' => 'REF-1', 'result' => 'fail', 'reason' => 'Model overreach'], ['id' => 'REF-6', 'result' => 'maybe', 'reason' => 'x']]);

        $this->assertSame(['REF-1', 'REF-2', 'REF-3', 'REF-4', 'REF-5', 'REF-6', 'REF-7', 'REF-8', 'REF-9'], array_column($checks, 'id'));
        $this->assertSame(['pass', 'Captured.'], [$checks[0]['result'], $checks[0]['detail']], 'an AI verdict on an SFA rule is ignored');
        $this->assertSame('unclear', $checks[5]['result'], 'a result outside the allowed list counts as unclear');
        $this->assertSame(['unclear', 'The AI gave no verdict on this rule.'], [$checks[6]['result'], $checks[6]['detail']]);
        $this->assertSame(['sfa', 'required', 'Payment was captured'], [$checks[0]['kind'], $checks[0]['severity'], $checks[0]['title']]);
        // A photo is now required evidence for a damaged or wrong item; the photo and amount checks only advise.
        $this->assertSame(['ai', 'required', 'Evidence of damage or a wrong item'], [$checks[6]['kind'], $checks[6]['severity'], $checks[6]['title']]);
        $this->assertSame([['ai', 'advisory', 'Photo matches the order'], ['ai', 'advisory', 'Amount fits the affected items']], [[$checks[7]['kind'], $checks[7]['severity'], $checks[7]['title']], [$checks[8]['kind'], $checks[8]['severity'], $checks[8]['title']]]);
        $this->assertStringContainsString('a photo shows the damage', AiRules::book('refund')['rules'][6]['description']);
    }

    public function test_the_risk_book_names_risk_signals_and_tri_3_matches_the_triage_code(): void
    {
        $risk = AiRules::book('risk');
        $this->assertSame('Payment risk signals', $risk['title']);
        $this->assertStringStartsWith('Low risk when no signal is found: suggest Clear.', $risk['decision']);
        $this->assertSame(['RSK-1', 'RSK-2', 'RSK-3', 'RSK-4', 'RSK-5'], array_column($risk['rules'], 'id'));
        $this->assertSame(['required', 'required', 'advisory', 'advisory', 'advisory'], array_column($risk['rules'], 'severity'));
        $this->assertSame('Checks whether the customer ever lost a payment dispute.', $risk['rules'][0]['description']);
        foreach ($risk['rules'] as $rule) { $this->assertStringStartsWith('Checks ', $rule['description'], $rule['id']); }
        $this->assertStringContainsString('more than 3 failed payment attempts', $risk['rules'][1]['description'], 'the {setting} placeholders are still filled in');
        $this->assertStringNotContainsStringIgnoringCase('compliance', json_encode(AiRules::books()));
        $this->assertSame('High for a delivery problem, a blocked order or a very upset requester, or for a money, safety or account-security ground that is not given Urgent.', AiRules::book('triage')['rules'][2]['description']);
    }

    public function test_the_outcome_follows_the_rules_not_the_model(): void
    {
        $all = fn (string $result) => AiRules::merge('refund', array_map(fn ($id) => AiRules::result($id, $result), ['REF-1', 'REF-2', 'REF-3', 'REF-4', 'REF-5']), [['id' => 'REF-6', 'result' => $result, 'reason' => ''], ['id' => 'REF-7', 'result' => 'not_applicable', 'reason' => ''], ['id' => 'REF-8', 'result' => 'not_applicable', 'reason' => ''], ['id' => 'REF-9', 'result' => $result, 'reason' => '']]);
        $this->assertSame('pass', AiRules::outcome($all('pass')));
        $this->assertSame('fail', AiRules::outcome($all('fail')));

        $advisory = $all('pass'); $advisory[4]['result'] = 'fail';
        $this->assertSame(['review', 1, 0], [AiRules::outcome($advisory), AiRules::failures($advisory, 'advisory'), AiRules::failures($advisory, 'required')]);
        $unclear = $all('pass'); $unclear[5]['result'] = 'unclear';
        $this->assertSame('review', AiRules::outcome($unclear));
    }
}
