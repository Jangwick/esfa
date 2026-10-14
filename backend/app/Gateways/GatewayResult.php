<?php
namespace App\Gateways;
final readonly class GatewayResult {
    public function __construct(public string $status, public string $reference, public ?string $failureReason=null, public ?array $nextAction=null) {}
}
