<?php

use Illuminate\Support\Facades\Broadcast;

// Public channel — no auth required for the demo workbench
Broadcast::channel('workflow.{runId}', fn ($runId) => true);
