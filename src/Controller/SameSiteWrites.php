<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;

/**
 * Écritures sans mot de passe (« Mes clés », ordre des cartes) : on refuse
 * celles déclenchées depuis un autre site (CSRF). Le navigateur dit d'où vient
 * la requête (Sec-Fetch-Site, Origin) ; un corps JSON impose en plus une
 * requête « préliminaire » qu'un autre site ne peut pas faire passer.
 */
trait SameSiteWrites
{
    private static function sameSiteOnly(Request $request): void
    {
        $site = $request->headers->get('Sec-Fetch-Site');
        if (null !== $site && !\in_array($site, ['same-origin', 'none'], true)) {
            throw new AccessDeniedHttpException('Requête venue d\'un autre site refusée.');
        }
        $origin = $request->headers->get('Origin');
        if (null !== $origin && parse_url($origin, \PHP_URL_HOST) !== $request->getHost()) {
            throw new AccessDeniedHttpException('Requête venue d\'un autre site refusée.');
        }
    }

    private static function jsonOnly(Request $request, string $expected): void
    {
        if ('json' !== $request->getContentTypeFormat()) {
            throw new UnsupportedMediaTypeHttpException(\sprintf('Corps JSON attendu : %s', $expected));
        }
    }
}
