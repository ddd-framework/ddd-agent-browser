<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Browser\Renderer;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The checks of the configured renderer deployments (stateless, sessions, or one deployment serving both).
 *
 * @property BrowserRendererDeploymentCheck[] $elements
 * @method BrowserRendererDeploymentCheck|null getByUniqueKey(string $uniqueKey)
 * @method BrowserRendererDeploymentCheck[] getElements()
 * @method BrowserRendererDeploymentCheck|null first()
 */
class BrowserRendererDeploymentChecks extends ObjectSet
{
    /** Every configured deployment answered and fits this package; false when none is configured */
    public function areAllHealthy(): bool
    {
        if ($this->count() === 0) {
            return false;
        }
        foreach ($this->getElements() as $browserRendererDeploymentCheck) {
            if ($browserRendererDeploymentCheck->problems !== []) {
                return false;
            }
        }
        return true;
    }
}
