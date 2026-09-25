<?php

namespace App\Enum;

/** How "Mettre à jour" installs a new version. */
enum UpdateMethod: string
{
    /** Watchtower pulls the new images and restarts the containers. */
    case Docker = 'docker';
    /** Server without Docker: the scheduled task app:update:run runs the update script (git, composer, npm, migrations). */
    case Script = 'script';
    /** No button: the page shows the commands to run. */
    case Manual = 'manual';
}
