<?php

declare(strict_types=1);

namespace DDD\Tests\Browser\Domain\Browser;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Exceptions\Conversations\OwnerConsentMissingException;
use DDD\Domain\Browser\Entities\Renderer\BrowserRendererContract;
use DDD\Domain\Browser\Entities\Sessions\BrowserSession;
use DDD\Domain\Browser\Entities\Sessions\BrowserSessionAnswer;
use DDD\Domain\Browser\Entities\Sessions\BrowserSessionRequest;
use DDD\Tests\Browser\Fakes\WiredBrowserAgentSessionsService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The consent gates of the agent side: a model-written URL or text without a known origin asks the operator; a
 * submission the renderer stopped is sent only after the operator's yes, then once with the release token.
 */
class BrowserAgentSessionsServiceTest extends TestCase
{
    public function testAUrlOfKnownOriginRunsWithoutAQuestion(): void
    {
        $wiredService = new WiredBrowserAgentSessionsService();
        $reservation = $wiredService->requireKnownOriginOfUrl($this->provenanceWithOperatorText('Open https://www.example.com/team'), 'https://www.example.com/team', 'browserNavigate');
        self::assertNull($reservation);
        self::assertSame([], $wiredService->recordingOwnerConsentsService->askedTargetKeys);
    }

    public function testAnUnknownUrlAsksTheOperatorAndRunsAfterTheYes(): void
    {
        $wiredService = new WiredBrowserAgentSessionsService();
        $provenance = $this->provenanceWithOperatorText('Find the imprint of example.com');
        try {
            $wiredService->requireKnownOriginOfUrl($provenance, 'https://collector.example.net/?d=secret', 'browserNavigate');
            self::fail('an unknown URL must ask the operator');
        } catch (OwnerConsentMissingException) {
        }
        self::assertSame(['browserNavigate|https://collector.example.net/?d=secret'], $wiredService->recordingOwnerConsentsService->askedTargetKeys);

        $wiredService->recordingOwnerConsentsService->isConsentGiven = true;
        self::assertNotNull($wiredService->requireKnownOriginOfUrl($provenance, 'https://collector.example.net/?d=secret', 'browserNavigate'));
    }

    public function testATextTheOperatorDidNotWriteAsksTheOperator(): void
    {
        $wiredService = new WiredBrowserAgentSessionsService();
        $provenance = $this->provenanceWithOperatorText('Search the site for Bonusprogramm');
        self::assertNull($wiredService->requireKnownOriginOfText($provenance, 'Bonusprogramm', 'https://www.example.com/', 'browserType'));
        $this->expectException(OwnerConsentMissingException::class);
        $wiredService->requireKnownOriginOfText($provenance, 'jane.doe@example.org', 'https://www.example.com/', 'browserType');
    }

    public function testAStoppedSubmissionIsSentOnlyAfterTheYesAndOnlyOnce(): void
    {
        $wiredService = new WiredBrowserAgentSessionsService();
        $provenance = $this->provenanceWithOperatorText('Send the contact form');
        $browserSession = $this->session();
        $actRequest = BrowserSessionRequest::forAction(BrowserRendererContract::SESSION_ACTION_ACT, $browserSession->sessionKey);
        $actRequest->actAction = 'click';
        $actRequest->ref = 'e9';

        $wiredService->scriptedBrowserRendererService->scriptedAnswers = [$this->consentRequiredAnswer()];
        try {
            $wiredService->runActWithSubmissionRule($provenance, $browserSession, $actRequest, 'browserClick');
            self::fail('a submission without the yes must not be sent');
        } catch (OwnerConsentMissingException $ownerConsentMissingException) {
            self::assertStringContainsString('POST to https://example.com/send', $ownerConsentMissingException->getMessage());
        }
        self::assertCount(1, $wiredService->scriptedBrowserRendererService->receivedRequests, 'no second request without the yes');
        self::assertContains('CONSENT_REQUIRED https://example.com/send', $wiredService->inMemoryBrowserSessionsService->recordedEvents);

        $wiredService->recordingOwnerConsentsService->isConsentGiven = true;
        $okAnswer = new BrowserSessionAnswer();
        $okAnswer->httpStatusCode = 200;
        $okAnswer->reason = BrowserRendererContract::REASON_OK;
        $wiredService->scriptedBrowserRendererService->scriptedAnswers = [$this->consentRequiredAnswer(), $okAnswer];
        $answer = $wiredService->runActWithSubmissionRule($provenance, $browserSession, $actRequest, 'browserClick');
        self::assertTrue($answer->isOk());
        $receivedRequests = $wiredService->scriptedBrowserRendererService->receivedRequests;
        self::assertSame('rt-1', end($receivedRequests)->releaseToken, 'the repeat carries the release token');
        self::assertNull($actRequest->releaseToken, 'the caller\'s request is not changed');
        self::assertSame(1, $wiredService->recordingOwnerConsentsService->usedCount, 'the yes is used up');
    }

    public function testANavigationWithoutASessionKeyOpensOne(): void
    {
        $wiredService = new WiredBrowserAgentSessionsService();
        $browserSession = BrowserSession::newInstance();
        $navigateAnswer = new BrowserSessionAnswer();
        $navigateAnswer->httpStatusCode = 200;
        $navigateAnswer->reason = BrowserRendererContract::REASON_OK;
        $navigateAnswer->sessionKey = 'fedcba9876543210fedcba9876543210';
        $wiredService->scriptedBrowserRendererService->scriptedAnswers = [$navigateAnswer];
        $navigateRequest = BrowserSessionRequest::forAction(BrowserRendererContract::SESSION_ACTION_NAVIGATE);
        $navigateRequest->url = 'https://www.example.com/';
        $wiredService->runAction($browserSession, $navigateRequest, 'browserNavigate');
        self::assertArrayNotHasKey('sessionKey', $wiredService->scriptedBrowserRendererService->receivedRequests[0]->toRendererBody());
        self::assertSame('fedcba9876543210fedcba9876543210', $browserSession->sessionKey, 'the renderer\'s key is kept for the next action');
    }

    protected function consentRequiredAnswer(): BrowserSessionAnswer
    {
        $browserSessionAnswer = new BrowserSessionAnswer();
        $browserSessionAnswer->action = BrowserRendererContract::SESSION_ACTION_ACT;
        $browserSessionAnswer->httpStatusCode = 200;
        $browserSessionAnswer->fillFromRendererData(json_decode('{"reason":"CONSENT_REQUIRED","submissions":[{"method":"POST","target":"https://example.com/send","fields":[{"name":"message","value":"hello","typed":true}],"releaseToken":"rt-1"}]}'));
        return $browserSessionAnswer;
    }

    protected function session(): BrowserSession
    {
        $browserSession = BrowserSession::newInstance();
        $browserSession->sessionKey = '0123456789abcdef0123456789abcdef';
        $browserSession->currentUrl = 'https://example.com/contact';
        return $browserSession;
    }

    protected function provenanceWithOperatorText(string $operatorText): AgentToolCallProvenance
    {
        /** @var AIConversationMessage $ownerMessage */
        $ownerMessage = (new ReflectionClass(AIConversationMessage::class))->newInstanceWithoutConstructor();
        $ownerMessage->role = AIConversationMessage::ROLE_USER;
        $ownerMessage->authorType = AIConversationMessage::AUTHOR_USER;
        $ownerMessage->messageType = AIConversationMessage::MESSAGE_TEXT;
        $ownerMessage->message = $operatorText;
        $ownerMessage->indexInConversation = 1;
        $conversationMessages = new AIConversationMessages();
        $conversationMessages->add($ownerMessage);
        return new AgentToolCallProvenance(sourceUserMessage: $ownerMessage, conversationId: 7, conversationMessages: $conversationMessages);
    }
}
