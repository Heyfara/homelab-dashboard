<?php

namespace App\Network;

/**
 * Point d'accès lu par la carte Réseau (option `access_points`).
 */
final readonly class AccessPoint
{
    public function __construct(
        public string $id,
        public string $address,
        /** Zone affichée : Bureau, Étage… */
        public string $zone,
        /** Port du bridge relié au switch de la baie */
        public string $uplink,
        /** openwrt : lu par SSH ; tplink : Archer C6 lu comme son interface web */
        public string $kind = 'openwrt',
    ) {
    }
}
