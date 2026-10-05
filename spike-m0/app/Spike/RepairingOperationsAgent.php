<?php

namespace App\Spike;

use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\RepairToolCalls;

/**
 * Same agent with the SDK's tool-call repair enabled (D3b). Not a security
 * boundary: exposure and execution policy still decide what runs.
 */
#[MaxSteps(6)]
#[RepairToolCalls]
class RepairingOperationsAgent extends OperationsAgent {}
