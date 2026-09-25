<?php

namespace App\Message;

/** Marker: messages implementing it go through the "async" transport (worker), see config/packages/messenger.yaml. */
interface AsyncMessageInterface
{
}
