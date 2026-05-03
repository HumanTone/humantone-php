<?php

declare(strict_types=1);

namespace HumanTone\Internal;

/**
 * Encodes the error rows of the §7.2 retry matrix as a discriminator
 * for {@see RetryPolicy::decide()}.
 *
 * The transport layer constructs the matching condition based on what
 * happened during the attempt; the policy maps it to a retry decision.
 */
enum ErrorCondition
{
    case Network;
    case Http5xx;
    case Http429;
    case Http4xxOther;
    case ClientTimeout;
    /** detect 200+success:false (transient backend error, no message) */
    case SuccessFalseDetect;
    /** humanize 200+success:false (currently never returned by server but reserved) */
    case SuccessFalseHumanize;
    /** JSON parse OR coercion failure on a 5xx response body */
    case ParseOrCoercionFailureOn5xx;
    /** JSON parse OR coercion failure on any non-5xx response body */
    case ParseOrCoercionFailureOnOther;
}
