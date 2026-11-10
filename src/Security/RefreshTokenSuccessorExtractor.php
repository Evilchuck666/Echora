<?php

namespace App\Security;

use Gesdinet\JWTRefreshTokenBundle\Request\Extractor\ExtractorInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpFoundation\Request;

/**
 * Hands the bundle the successor RefreshTokenGraceListener chose, instead of the spent token in the body. First in
 * the bundle's extractor chain, so its authenticator, its rotation listener and RefreshTokenLockListener all see the
 * same token.
 */
#[AutoconfigureTag('gesdinet_jwt_refresh_token.request_extractor', ['priority' => 100])]
final class RefreshTokenSuccessorExtractor implements ExtractorInterface
{
    public const string ATTRIBUTE = '_refresh_token_successor';

    public function getRefreshToken(Request $request, string $parameter): ?string
    {
        $successor = $request->attributes->get(self::ATTRIBUTE);

        return \is_string($successor) ? $successor : null;
    }
}
