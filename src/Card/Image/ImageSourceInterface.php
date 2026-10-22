<?php

namespace App\Card\Image;

use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Source\SourceUnavailableException;

/**
 * Un type de carte dont les images exigent une authentification (Immich,
 * Calibre-Web) : le dashboard les relaie (CardImageController) au lieu de
 * confier la clé au navigateur. Ses URL d'image viennent d'ImageUrlSigner.
 */
interface ImageSourceInterface
{
    /**
     * @param string $path chemin signé, tel que passé à ImageUrlSigner::url()
     *
     * @throws SourceUnavailableException
     */
    public function fetchImage(string $path, CardInstance $card, Dashboard $dashboard): CardImage;
}
