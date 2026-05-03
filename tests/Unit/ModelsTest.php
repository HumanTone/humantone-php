<?php

declare(strict_types=1);

namespace HumanTone\Tests\Unit;

use DateTimeImmutable;
use HumanTone\Enums\OutputFormat;
use HumanTone\Models\AccountInfo;
use HumanTone\Models\Credits;
use HumanTone\Models\DetectResult;
use HumanTone\Models\HumanizeResult;
use HumanTone\Models\Plan;
use HumanTone\Models\Subscription;
use PHPUnit\Framework\TestCase;

final class ModelsTest extends TestCase
{
    public function testPlanConstruction(): void
    {
        $p = new Plan(
            id: 'pro_monthly',
            name: 'Pro Monthly',
            maxWords: 1500,
            monthlyCredits: 1000,
            apiAccess: true,
        );
        $this->assertSame('pro_monthly', $p->id);
        $this->assertSame('Pro Monthly', $p->name);
        $this->assertSame(1500, $p->maxWords);
        $this->assertSame(1000, $p->monthlyCredits);
        $this->assertTrue($p->apiAccess);
    }

    public function testCreditsConstruction(): void
    {
        $c = new Credits(trial: 0, subscription: 820, extra: 150, total: 970);
        $this->assertSame(0, $c->trial);
        $this->assertSame(820, $c->subscription);
        $this->assertSame(150, $c->extra);
        $this->assertSame(970, $c->total);
    }

    public function testSubscriptionWithExpiresAt(): void
    {
        $dt = new DateTimeImmutable('2026-05-08T00:00:00.000Z');
        $s = new Subscription(active: true, expiresAt: $dt);
        $this->assertTrue($s->active);
        $this->assertSame($dt, $s->expiresAt);
    }

    public function testSubscriptionWithNullExpiresAt(): void
    {
        $s = new Subscription(active: false, expiresAt: null);
        $this->assertFalse($s->active);
        $this->assertNull($s->expiresAt);
    }

    public function testAccountInfoComposition(): void
    {
        $info = new AccountInfo(
            plan: new Plan('basic', 'Basic', 750, 100, true),
            credits: new Credits(0, 100, 0, 100),
            subscription: new Subscription(true, null),
        );
        $this->assertSame('basic', $info->plan->id);
        $this->assertSame(100, $info->credits->total);
        $this->assertTrue($info->subscription->active);
    }

    public function testHumanizeResultConstruction(): void
    {
        $r = new HumanizeResult(
            text: 'Humanized text',
            outputFormat: OutputFormat::Text,
            creditsUsed: 3,
            requestId: '550e8400-e29b-41d4-a716-446655440000',
        );
        $this->assertSame('Humanized text', $r->text);
        $this->assertSame(OutputFormat::Text, $r->outputFormat);
        $this->assertSame(3, $r->creditsUsed);
        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $r->requestId);
    }

    public function testHumanizeResultRequestIdNullable(): void
    {
        $r = new HumanizeResult('t', OutputFormat::Html, 1, null);
        $this->assertNull($r->requestId);
    }

    public function testDetectResultConstruction(): void
    {
        $r = new DetectResult(aiScore: 87, requestId: 'req-9');
        $this->assertSame(87, $r->aiScore);
        $this->assertSame('req-9', $r->requestId);
    }

    public function testDetectResultRequestIdDefaultsToNull(): void
    {
        $r = new DetectResult(aiScore: 42);
        $this->assertNull($r->requestId);
    }
}
