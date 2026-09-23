<?php

declare(strict_types=1);

// @mago-expect analysis:mixed-array-assignment
// @mago-expect analysis:undefined-variable(2)
/** @disregard */
$EM_CONF[$_EXTKEY] = [
    'title' => 'Fluid Types',
    'description' => 'Generates TypeScript types from Fluid Primitives component props and any #[TypeScript]-tagged PHP class in a TYPO3 project.',
    'category' => 'misc',
    'constraints' => [
        'depends' => [
            'typo3' => '14.0.0-14.3.99',
        ],
        'conflicts' => [],
    ],
    'autoload' => [
        'psr-4' => [
            'Jramke\\FluidTypes\\' => 'Classes',
        ],
    ],
    'state' => 'stable',
    'author' => 'Joost Ramke',
    'author_email' => 'hey@joostramke.com',
    'author_company' => 'jramke',
    'version' => '0.1.0',
];
