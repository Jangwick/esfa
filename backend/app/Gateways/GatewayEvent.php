<?php
namespace App\Gateways;
final readonly class GatewayEvent {
    public function __construct(public string $id, public string $type, public string $reference, public ?int $amount=null) {}
}
