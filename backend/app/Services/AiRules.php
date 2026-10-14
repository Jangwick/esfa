<?php

namespace App\Services;

use App\Models\Setting;

/**
 * The rulebooks behind the AI features. Each rule is checked by SFA's own code (kind "sfa") or judged by the AI
 * against the facts it was given (kind "ai"), and is "required" or "advisory". The outcome of a check is worked out here
 * from the rule results, never by the model, so every recommendation shows the rules it rests on. {placeholders} in a
 * description are settings (config/sfa.php), filled in by book().
 */
class AiRules
{
    public const RESULTS = ['pass', 'fail', 'unclear', 'not_applicable'];

    // Failed-delivery reason -> the next step staff take; the AI only picks the reason.
    public const DELIVERY_PLAYBOOK = [
        'recipient_unavailable' => 'Contact the customer to agree on a delivery time, then ask the courier to try again.',
        'wrong_or_incomplete_address' => 'Confirm the full address and a landmark with the customer, update the shipment, then ask the courier to try again.',
        'refused' => 'Ask the customer why the parcel was refused; if they no longer want it, cancel the order or arrange the return.',
        'inaccessible_area' => 'Agree with the customer on a meeting point or a pickup at the courier hub.',
        'cod_cash_not_ready' => 'Remind the customer of the cash-on-delivery amount and agree on a date for the next attempt.',
        'rescheduled_by_customer' => 'Note the date the customer asked for and ask the courier to deliver on that day.',
        'other' => 'Read the courier note, contact the customer and decide the next step.',
    ];

    private const BOOKS = [
        'refund' => ['title' => 'Refund policy', 'used_by' => 'Finance review of refund requests',
            'decision' => 'Approve when every rule passes. Reject when a required rule fails. Otherwise ask for more information.', 'rules' => [
                ['id' => 'REF-1', 'kind' => 'sfa', 'severity' => 'required', 'title' => 'Payment was captured', 'description' => 'Only a captured or partially refunded payment can be refunded.'],
                ['id' => 'REF-2', 'kind' => 'sfa', 'severity' => 'required', 'title' => 'Within the refundable balance', 'description' => 'The amount is no more than what was captured, less refunds already made and other refunds waiting.'],
                ['id' => 'REF-3', 'kind' => 'sfa', 'severity' => 'required', 'title' => 'Within the refund window', 'description' => 'Requested within {refund_window_days} days of delivery, or of payment when nothing was delivered.'],
                ['id' => 'REF-4', 'kind' => 'sfa', 'severity' => 'required', 'title' => 'Reason fits the delivery', 'description' => 'Item not received: no parcel was delivered with proof. Damaged or wrong item: a parcel was delivered. Cancelled order: no parcel left the warehouse. Duplicate charge: the customer has another captured payment of the same amount.'],
                ['id' => 'REF-5', 'kind' => 'sfa', 'severity' => 'advisory', 'title' => 'Refund history', 'description' => 'The customer had no more than {refund_max_per_30_days} approved or completed refunds in the last 30 days.'],
                ['id' => 'REF-6', 'kind' => 'ai', 'severity' => 'required', 'title' => 'Problem described', 'description' => 'The notes or the support conversation describe the problem in words, and it matches the reason given.'],
                ['id' => 'REF-7', 'kind' => 'ai', 'severity' => 'required', 'title' => 'Evidence of damage or a wrong item', 'description' => 'For a damaged or wrong item, a photo shows the damage or the item that arrived. Does not apply to other reasons.'],
                ['id' => 'REF-8', 'kind' => 'ai', 'severity' => 'advisory', 'title' => 'Photo matches the order', 'description' => 'Each photo shows this parcel or an item from this order (for a wrong item, something different from what was ordered) and looks like a real photo, not a stock image, a screenshot of a product listing or an edited picture. Does not apply when there is no photo.'],
                ['id' => 'REF-9', 'kind' => 'ai', 'severity' => 'advisory', 'title' => 'Amount fits the affected items', 'description' => 'The amount is no more than the price of the items the notes, conversation or photos say are affected; shipping counts only when the whole order is refunded.'],
            ]],
        // A "fail" here means the risk signal was found; a required rule is a major signal.
        'risk' => ['title' => 'Payment risk signals', 'used_by' => 'Finance review of risk reviews and the second opinion on payments',
            'decision' => 'Low risk when no signal is found: suggest Clear. High risk when a major signal is found: suggest Block. Otherwise some risk: look closer. The second opinion adds a payment to Risk reviews when a major signal is found or two or more signals are found.', 'rules' => [
                ['id' => 'RSK-1', 'kind' => 'sfa', 'severity' => 'required', 'title' => 'No lost disputes', 'description' => 'Checks whether the customer ever lost a payment dispute.'],
                ['id' => 'RSK-2', 'kind' => 'sfa', 'severity' => 'required', 'title' => 'Failed attempts within the limit', 'description' => 'Checks whether there were more than {risk_failed_attempts} failed payment attempts in the hour before this payment.'],
                ['id' => 'RSK-3', 'kind' => 'sfa', 'severity' => 'advisory', 'title' => 'Amount fits the customer\'s history', 'description' => 'Checks whether the amount is more than {risk_amount_multiple} times the customer\'s largest captured payment. A customer with no captured payment counts as this signal.'],
                ['id' => 'RSK-4', 'kind' => 'sfa', 'severity' => 'advisory', 'title' => 'Established customer', 'description' => 'Checks whether the customer record is less than {risk_new_customer_days} days old.'],
                ['id' => 'RSK-5', 'kind' => 'ai', 'severity' => 'advisory', 'title' => 'Nothing unusual in the order or tickets', 'description' => 'Checks the order items and related tickets for signs of fraud, such as complaints about charges the customer did not make.'],
            ]],
        'reply' => ['title' => 'Reply guidelines', 'used_by' => 'Reply drafts',
            'decision' => 'A draft that fails a required rule is marked for correction before it is sent.', 'rules' => [
                ['id' => 'RPL-1', 'kind' => 'sfa', 'severity' => 'required', 'title' => 'No request for secrets', 'description' => 'Never asks for a password, one-time code, PIN or full card number.'],
                ['id' => 'RPL-2', 'kind' => 'sfa', 'severity' => 'required', 'title' => 'Amounts come from SFA', 'description' => 'Every peso amount in the draft appears in the ticket\'s order, payment or refund records.'],
                ['id' => 'RPL-3', 'kind' => 'sfa', 'severity' => 'required', 'title' => 'References come from SFA', 'description' => 'Every payment, refund, shipment or ticket reference in the draft belongs to this ticket\'s records.'],
                ['id' => 'RPL-4', 'kind' => 'sfa', 'severity' => 'advisory', 'title' => 'Short', 'description' => 'No more than 150 words.'],
                ['id' => 'RPL-5', 'kind' => 'sfa', 'severity' => 'advisory', 'title' => 'Personal greeting', 'description' => 'Greets the requester by first name.'],
                ['id' => 'RPL-6', 'kind' => 'ai', 'severity' => 'required', 'title' => 'No unsupported promises', 'description' => 'Promises nothing the records do not support, such as an approved refund, a delivery date or compensation.'],
                ['id' => 'RPL-7', 'kind' => 'ai', 'severity' => 'advisory', 'title' => 'Follows the knowledge base', 'description' => 'Follows the published knowledge-base article when one covers the question.'],
            ]],
        'triage' => ['title' => 'Ticket triage policy', 'used_by' => 'Ticket triage',
            'decision' => 'The AI names the ground for the priority. SFA keeps the priority at or below that ground\'s level and checks the money grounds against its records.', 'rules' => [
                ['id' => 'TRI-1', 'kind' => 'sfa', 'severity' => 'required', 'title' => 'Never lowers a priority', 'description' => 'Raises only the default priority Normal; a priority a person chose is kept.'],
                ['id' => 'TRI-2', 'kind' => 'sfa', 'severity' => 'required', 'title' => 'Urgent needs a confirmed ground', 'description' => 'Urgent only for a duplicate charge or money taken for a cancelled order that SFA\'s records confirm, or for a safety or account-security problem.'],
                ['id' => 'TRI-3', 'kind' => 'sfa', 'severity' => 'required', 'title' => 'High needs a service ground', 'description' => 'High for a delivery problem, a blocked order or a very upset requester, or for a money, safety or account-security ground that is not given Urgent.'],
                ['id' => 'TRI-4', 'kind' => 'sfa', 'severity' => 'required', 'title' => 'Category from SFA\'s list', 'description' => 'Replaces only the category Other, with one of SFA\'s ticket categories.'],
            ]],
        'address' => ['title' => 'Address rules', 'used_by' => 'Address fill on Create shipment',
            'decision' => 'Location ids come only from SFA\'s tables; any part that does not match is left for the person to pick.', 'rules' => [
                ['id' => 'ADR-1', 'kind' => 'sfa', 'severity' => 'required', 'title' => 'Name and phone stay in SFA', 'description' => 'The recipient\'s name and phone number are read by SFA and never sent to the AI.'],
                ['id' => 'ADR-2', 'kind' => 'sfa', 'severity' => 'required', 'title' => 'Locations from SFA\'s tables', 'description' => 'Region, city and barangay are matched to SFA\'s location list; the AI never supplies an id.'],
                ['id' => 'ADR-3', 'kind' => 'sfa', 'severity' => 'required', 'title' => 'City agrees with the province', 'description' => 'A city is used only when it is the only one with that name in the province or region that was read.'],
                ['id' => 'ADR-4', 'kind' => 'sfa', 'severity' => 'advisory', 'title' => 'Postal code format', 'description' => 'The ZIP code has 4 digits.'],
            ]],
        'delivery' => ['title' => 'Failed-delivery playbook', 'used_by' => 'Failed-delivery reason on delivery tickets',
            'decision' => 'The AI picks the reason from SFA\'s list; the next step comes from SFA\'s playbook, not from the AI.', 'rules' => [
                ['id' => 'DLV-1', 'kind' => 'sfa', 'severity' => 'required', 'title' => 'Reason from SFA\'s list', 'description' => 'One of: recipient unavailable, wrong or incomplete address, refused, inaccessible area, COD cash not ready, rescheduled by the customer, other.'],
                ['id' => 'DLV-2', 'kind' => 'sfa', 'severity' => 'required', 'title' => 'Next step from the playbook', 'description' => 'Each reason has one fixed next step in SFA\'s playbook.'],
            ]],
        'ask' => ['title' => 'Reporting rules', 'used_by' => 'Ask your data',
            'decision' => 'An answer with a figure that cannot be found in the reports it used is marked for checking.', 'rules' => [
                ['id' => 'ASK-1', 'kind' => 'sfa', 'severity' => 'required', 'title' => 'Figures from SFA\'s reports', 'description' => 'Every amount or rate in the answer appears in the report results it used.'],
                ['id' => 'ASK-2', 'kind' => 'sfa', 'severity' => 'required', 'title' => 'Within the asker\'s access', 'description' => 'Reports run with the asker\'s own role, so the answer covers only what they can see.'],
                ['id' => 'ASK-3', 'kind' => 'sfa', 'severity' => 'advisory', 'title' => 'Dates named', 'description' => 'The answer states the dates it covers.'],
            ]],
    ];

    // One rulebook with its settings filled in: ['title', 'used_by', 'decision', 'rules' => [[id, kind, severity, title, description]]].
    public static function book(string $name): array
    {
        $book = self::BOOKS[$name];
        $fill = fn (string $text) => preg_replace_callback('/\{(\w+)\}/', fn ($match) => (string) Setting::valueFor($match[1]), $text);
        $book['decision'] = $fill($book['decision']);
        $book['rules'] = array_map(fn ($rule) => ['description' => $fill($rule['description'])] + $rule, $book['rules']);

        return $book;
    }

    // Every rulebook, plus the delivery playbook, for GET /ai/rules.
    public static function books(): array
    {
        return ['books' => array_map(fn ($name) => ['name' => $name] + self::book($name), array_keys(self::BOOKS)), 'delivery_playbook' => self::DELIVERY_PLAYBOOK];
    }

    // An SFA check result for merge(); $detail says what SFA found, in a sentence staff can read.
    public static function result(string $id, string $result, string $detail = ''): array
    {
        return ['id' => $id, 'result' => $result, 'detail' => $detail];
    }

    // The AI-judged rules of a book, for the prompt: [[id, title, description]].
    public static function aiRules(string $name): array
    {
        return array_values(array_map(fn ($rule) => ['id' => $rule['id'], 'title' => $rule['title'], 'description' => $rule['description']], array_filter(self::book($name)['rules'], fn ($rule) => $rule['kind'] === 'ai')));
    }

    // JSON schema of the model's verdicts on the AI-judged rules of a book.
    public static function aiSchema(string $name): array
    {
        return ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
            'id' => ['type' => 'string', 'enum' => array_column(self::aiRules($name), 'id')],
            'result' => ['type' => 'string', 'enum' => self::RESULTS],
            'reason' => ['type' => 'string'],
        ], 'required' => ['id', 'result', 'reason']]];
    }

    /**
     * Every rule of a book in order, with its title, kind and severity, and the result SFA ($sfa, from result()) or the AI
     * ($ai, the model's verdicts) gave it. A rule nobody judged, or a verdict outside the allowed results, is "unclear";
     * a verdict for a rule that is not the AI's to judge is ignored.
     */
    public static function merge(string $name, array $sfa, array $ai = []): array
    {
        $sfa = collect($sfa)->keyBy('id');
        $ai = collect($ai)->filter(fn ($verdict) => is_array($verdict) && isset($verdict['id']))->keyBy('id');

        return array_map(function ($rule) use ($sfa, $ai) {
            $found = $rule['kind'] === 'sfa' ? $sfa->get($rule['id']) : $ai->get($rule['id']);
            $result = in_array($found['result'] ?? null, self::RESULTS, true) ? $found['result'] : 'unclear';
            $detail = mb_substr((string) ($found['detail'] ?? $found['reason'] ?? ($found ? '' : ($rule['kind'] === 'ai' ? 'The AI gave no verdict on this rule.' : 'Not checked.'))), 0, 500);

            return ['id' => $rule['id'], 'kind' => $rule['kind'], 'severity' => $rule['severity'], 'title' => $rule['title'], 'result' => $result, 'detail' => $detail];
        }, self::book($name)['rules']);
    }

    // "fail" when a required rule fails; "review" when a required rule is unclear or an advisory rule fails or is unclear;
    // otherwise "pass". Rules that do not apply count as passed.
    public static function outcome(array $checks): string
    {
        $checks = collect($checks);
        if ($checks->contains(fn ($check) => $check['severity'] === 'required' && $check['result'] === 'fail')) { return 'fail'; }

        return $checks->contains(fn ($check) => in_array($check['result'], ['fail', 'unclear'], true)) ? 'review' : 'pass';
    }

    // How many rules failed, optionally only those of one severity.
    public static function failures(array $checks, ?string $severity = null): int
    {
        return collect($checks)->filter(fn ($check) => $check['result'] === 'fail' && (! $severity || $check['severity'] === $severity))->count();
    }
}
