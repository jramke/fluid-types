<?php

declare(strict_types=1);

namespace Jramke\FluidTypes\Discovery;

use Jramke\FluidPrimitives\Component\AbstractComponentCollection;
use Jramke\FluidPrimitives\Utility\ComponentNameUtility;

/**
 * Lists every root component a collection knows about. Nothing in fluid-primitives itself
 * maintains such a list (`getComponentDefinition()` requires a name up front), so this walks the
 * collection's own template root paths and, per directory, tries each candidate
 * {@see ComponentNameUtility::getRootViewHelperNameCandidates()} recognizes (a single-file
 * component, or one with its own ".root" sub-parts) through the collection's own
 * `resolveTemplateName()`/`getTemplatePaths()` - the same existence check
 * `resolveViewHelperClassName()` uses internally - rather than re-implementing Fluid's own
 * template-resolution/format rules here.
 *
 * Deduplicates by viewHelperName across multiple template root paths: `TemplatePaths` treats
 * several root paths as a fallback chain (the first one that has a given template wins, e.g.
 * the docs site's own collection registers both a "ui" wrapper tree and a plain "Components"
 * tree), not independent sets to enumerate separately - scanning each path unconditionally would
 * otherwise report the same component more than once whenever a later root path happens to have
 * a same-named directory that's actually unreachable/shadowed for that name.
 */
final class RootComponentEnumerator
{
    /**
     * @return list<string> root viewHelperNames, e.g. "select.root" or "clipboard"
     */
    public function enumerate(AbstractComponentCollection $collection): array
    {
        /** @var array<string, true> $viewHelperNames */
        $viewHelperNames = [];

        foreach ($collection->getTemplatePaths()->getTemplateRootPaths() as $rootPath) {
            $rootPath = rtrim($rootPath, '/');
            if (!is_dir($rootPath)) {
                continue;
            }

            foreach (scandir($rootPath) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..' || !is_dir($rootPath . '/' . $entry)) {
                    continue;
                }

                foreach (ComponentNameUtility::getRootViewHelperNameCandidates($entry) as $candidate) {
                    if (isset($viewHelperNames[$candidate])) {
                        break;
                    }

                    $templateName = $collection->resolveTemplateName($candidate);

                    if ($collection->getTemplatePaths()->resolveTemplateFileForControllerAndActionAndFormat(
                        'Default',
                        $templateName,
                    )) {
                        $viewHelperNames[$candidate] = true;
                        // Only one of a directory's candidates should ever resolve to a real
                        // template - once found, the other is moot.
                        break;
                    }
                }
            }
        }

        return array_keys($viewHelperNames);
    }
}
