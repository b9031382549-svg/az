<?php

namespace App\Services\Classify\Mechanisms;

// A single, independent way of finding the XİF MN code for an item (vector
// retrieval, broker-descent, ...). Several mechanisms run in parallel per item;
// their MechanismResults are stored side by side and reconciled into a consensus.
interface ClassifierMechanism
{
    /** Stable identifier stored in classification_results.mechanism (e.g. 'vector'). */
    public function key(): string;

    /** Classify one free-text line item into a MechanismResult. */
    public function classify(string $text): MechanismResult;
}
