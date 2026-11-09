<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Search;
use App\Search\LibrarySearch;
use Symfony\Component\HttpFoundation\Request;

/**
 * Runs the search of GET /api/search. Its parameters are validated before this runs (see Search).
 *
 * @implements ProviderInterface<Search>
 */
final readonly class SearchProvider implements ProviderInterface
{
    public function __construct(private LibrarySearch $search)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Search
    {
        $request = $context['request'] ?? null;
        \assert($request instanceof Request);

        return $this->search->search(
            $request->query->getString('q'),
            $request->query->getInt('limit', Search::DEFAULT_LIMIT),
        );
    }
}
