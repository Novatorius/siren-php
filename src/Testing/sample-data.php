<?php

declare(strict_types=1);

// Each kind's example request data and mode, copied from Siren's feature contracts
// (tests/Fixtures/apps-feature-contracts/requests.json, from Siren 6d2abd936a).
// Siren\Sdk\Testing\AppRequests reads it. AppRequestDxTest holds the two equal.

return array (
  'payoutMethod' => 
  array (
    'mode' => 'ask',
    'data' => 
    array (
      'payout' => 
      array (
        'id' => 88,
        'fulfillmentId' => 31,
        'attempt' => 1,
      ),
      'amount' => 
      array (
        'value' => 2500,
        'units' => 'USD',
        'currency' => 
        array (
          'id' => 'USD',
          'symbol' => '$',
          'position' => 'before',
          'name' => 'US Dollar',
          'scale' => 2,
          'decimalPlaces' => 2,
          'type' => 'monetary',
        ),
      ),
      'collaborator' => 
      array (
        'id' => 42,
        'name' => 'Ana Ruiz',
        'email' => 'ana@example.com',
      ),
    ),
  ),
  'incentiveResolver' => 
  array (
    'mode' => 'ask',
    'data' => 
    array (
      'reason' => 'sale',
      'program' => 
      array (
        'id' => 3,
        'name' => 'Partner program',
        'status' => 'active',
      ),
      'conversionType' => 'sale',
      'transaction' => 
      array (
        'id' => 3307,
        'units' => 'USD',
        'currency' => 
        array (
          'id' => 'USD',
          'symbol' => '$',
          'position' => 'before',
          'name' => 'US Dollar',
          'scale' => 2,
          'decimalPlaces' => 2,
          'type' => 'monetary',
        ),
      ),
      'pool' => 
      array (
        'value' => 1500,
        'units' => 'USD',
        'currency' => 
        array (
          'id' => 'USD',
          'symbol' => '$',
          'position' => 'before',
          'name' => 'US Dollar',
          'scale' => 2,
          'decimalPlaces' => 2,
          'type' => 'monetary',
        ),
      ),
      'conversions' => 
      array (
        0 => 
        array (
          'conversionId' => 1042,
          'engagementId' => 880,
          'collaboratorId' => 42,
          'collaboratorStatus' => 'active',
          'score' => 60,
          'engagementCreatedDate' => '2026-11-02T09:15:00+00:00',
        ),
        1 => 
        array (
          'conversionId' => 1043,
          'engagementId' => 881,
          'collaboratorId' => 57,
          'collaboratorStatus' => 'active',
          'score' => 40,
          'engagementCreatedDate' => '2026-11-09T16:40:00+00:00',
        ),
      ),
    ),
  ),
  'postConversionAction' => 
  array (
    'mode' => 'tell',
    'data' => 
    array (
      'conversion' => 
      array (
        'id' => 1042,
        'type' => 'sale',
        'status' => 'complete',
        'engagementId' => 880,
        'opportunityId' => 615,
        'collaboratorId' => 42,
        'transactionId' => 3307,
        'obligationId' => 2210,
      ),
      'reward' => 
      array (
        'value' => 1500,
        'units' => 'USD',
        'currency' => 
        array (
          'id' => 'USD',
          'symbol' => '$',
          'position' => 'before',
          'name' => 'US Dollar',
          'scale' => 2,
          'decimalPlaces' => 2,
          'type' => 'monetary',
        ),
      ),
      'program' => 
      array (
        'id' => 3,
        'name' => 'Partner program',
      ),
    ),
  ),
  'postAllocationAction' => 
  array (
    'mode' => 'tell',
    'data' => 
    array (
      'allocation' => 
      array (
        'distributionId' => 21,
        'obligationId' => 2290,
        'collaboratorId' => 42,
        'status' => 'complete',
        'value' => 6000,
        'units' => 'USD',
        'currency' => 
        array (
          'id' => 'USD',
          'symbol' => '$',
          'position' => 'before',
          'name' => 'US Dollar',
          'scale' => 2,
          'decimalPlaces' => 2,
          'type' => 'monetary',
        ),
      ),
      'distributor' => 
      array (
        'id' => 2,
        'name' => 'Partner pool',
      ),
    ),
  ),
  'distributionResolver' => 
  array (
    'mode' => 'ask',
    'data' => 
    array (
      'distribution' => 
      array (
        'id' => 21,
        'distributorId' => 2,
        'periodStart' => '2026-10-01T00:00:00+00:00',
        'periodEnd' => '2026-11-01T00:00:00+00:00',
      ),
      'distributor' => 
      array (
        'id' => 2,
        'name' => 'Partner pool',
      ),
      'pool' => 
      array (
        'value' => 10000,
        'total' => 10000,
        'paid' => 0,
        'units' => 'USD',
        'currency' => 
        array (
          'id' => 'USD',
          'symbol' => '$',
          'position' => 'before',
          'name' => 'US Dollar',
          'scale' => 2,
          'decimalPlaces' => 2,
          'type' => 'monetary',
        ),
      ),
      'metrics' => 
      array (
        0 => 
        array (
          'metricId' => 41,
          'collaboratorId' => 42,
          'score' => 30,
          'triggerCount' => 3,
        ),
        1 => 
        array (
          'metricId' => 43,
          'collaboratorId' => 57,
          'score' => 20,
          'triggerCount' => 2,
        ),
      ),
    ),
  ),
  'metricCalculation' => 
  array (
    'mode' => 'ask',
    'data' => 
    array (
      'distributor' => 
      array (
        'id' => 2,
        'name' => 'Partner leaderboard',
      ),
      'distribution' => 
      array (
        'id' => 21,
        'periodEnd' => '2026-12-01T00:00:00+00:00',
      ),
      'metricType' => 'referredSiteVisit',
      'distributorMetricTypeId' => 55,
      'triggeringCollaborator' => 
      array (
        'id' => 42,
        'name' => 'Riley Rep',
      ),
      'opportunityId' => 615,
      'points' => NULL,
      'cause' => 
      array (
        'id' => '9f2c4e1a7b3d5c60',
        'type' => NULL,
        'causeId' => NULL,
      ),
      'sale' => NULL,
    ),
  ),
  'metricFilter' => 
  array (
    'mode' => 'ask',
    'data' => 
    array (
      'distributor' => 
      array (
        'id' => 2,
        'name' => 'Partner leaderboard',
      ),
      'distribution' => 
      array (
        'id' => 21,
      ),
      'collaborator' => 
      array (
        'id' => 42,
        'name' => 'Riley Rep',
      ),
      'metricType' => 'awardedSale',
      'opportunityId' => 615,
      'points' => NULL,
      'cause' => 
      array (
        'id' => '4b7e0c9d2a1f3e58',
        'type' => 'transaction',
        'causeId' => '3307',
      ),
      'sale' => 
      array (
        'transactionId' => 3307,
        'conversionId' => 1042,
        'excludedLineIds' => 
        array (
        ),
      ),
    ),
  ),
  'engagementCalculation' => 
  array (
    'mode' => 'ask',
    'data' => 
    array (
      'program' => 
      array (
        'id' => 3,
        'name' => 'Partner program',
      ),
      'engagementType' => 'referredSiteVisit',
      'programEngagementTypeId' => 55,
      'args' => 
      array (
        'weight' => '2',
      ),
      'opportunity' => 
      array (
        'id' => 615,
        'customerId' => 77,
      ),
      'triggeringCollaborator' => 
      array (
        'id' => 42,
        'name' => 'Riley Rep',
      ),
      'cause' => 
      array (
        'id' => '9f2c4e1a7b3d5c60',
        'event' => 'referredSiteVisit',
        'occurredAt' => '2026-11-17T12:20:00+00:00',
      ),
    ),
  ),
  'engagementFilter' => 
  array (
    'mode' => 'ask',
    'data' => 
    array (
      'program' => 
      array (
        'id' => 3,
        'name' => 'Partner program',
      ),
      'collaborator' => 
      array (
        'id' => 42,
        'name' => 'Riley Rep',
      ),
      'opportunity' => 
      array (
        'id' => 615,
        'customerId' => 77,
      ),
      'engagementType' => 'referredSiteVisit',
      'cause' => 
      array (
        'id' => '4b7e0c9d2a1f3e58',
        'event' => 'referredSiteVisit',
        'occurredAt' => '2026-11-17T12:20:00+00:00',
      ),
    ),
  ),
  'incentiveType' => 
  array (
    'mode' => 'ask',
    'data' => 
    array (
      'reason' => 'sale',
      'program' => 
      array (
        'id' => 3,
        'name' => 'Partner program',
        'units' => 'USD',
      ),
      'conversionType' => 'sale',
      'opportunity' => 
      array (
        'id' => 615,
      ),
      'transaction' => 
      array (
        'id' => 3307,
        'status' => 'complete',
        'units' => 'USD',
        'currency' => 
        array (
          'id' => 'USD',
          'symbol' => '$',
          'position' => 'before',
          'name' => 'US Dollar',
          'scale' => 2,
          'decimalPlaces' => 2,
          'type' => 'monetary',
        ),
        'total' => 15000,
        'lines' => 
        array (
          0 => 
          array (
            'id' => 5501,
            'type' => 'lineItem',
            'name' => 'Annual plan',
            'quantity' => 1,
            'value' => 15000,
          ),
        ),
      ),
      'binding' => NULL,
      'engagements' => 
      array (
        0 => 
        array (
          'engagementId' => 880,
          'collaboratorId' => 42,
          'score' => 60,
          'createdDate' => '2026-11-02T09:15:00+00:00',
        ),
        1 => 
        array (
          'engagementId' => 881,
          'collaboratorId' => 57,
          'score' => 40,
          'createdDate' => '2026-11-09T16:40:00+00:00',
        ),
      ),
    ),
  ),
  'programGroupSorter' => 
  array (
    'mode' => 'ask',
    'data' => 
    array (
      'group' => 
      array (
        'id' => 5,
        'name' => 'Partner programs',
      ),
      'conversionType' => 'sale',
      'opportunity' => 
      array (
        'id' => 615,
      ),
      'transaction' => 
      array (
        'id' => 3307,
        'units' => 'USD',
        'total' => 15000,
      ),
      'programs' => 
      array (
        0 => 
        array (
          'id' => 12,
          'name' => 'Resellers',
          'engagements' => 
          array (
            0 => 
            array (
              'engagementId' => 880,
              'collaboratorId' => 42,
              'score' => 40,
              'createdDate' => '2026-11-02T09:15:00+00:00',
            ),
          ),
        ),
        1 => 
        array (
          'id' => 9,
          'name' => 'Affiliates',
          'engagements' => 
          array (
            0 => 
            array (
              'engagementId' => 881,
              'collaboratorId' => 57,
              'score' => 60,
              'createdDate' => '2026-11-09T16:40:00+00:00',
            ),
          ),
        ),
      ),
    ),
  ),
  'transactionFilter' => 
  array (
    'mode' => 'ask',
    'data' => 
    array (
      'for' => 
      array (
        'type' => 'program',
        'id' => 3,
        'name' => 'Partner program',
      ),
      'transaction' => 
      array (
        'id' => 3307,
        'units' => 'USD',
        'currency' => 
        array (
          'id' => 'USD',
          'symbol' => '$',
          'position' => 'before',
          'name' => 'US Dollar',
          'scale' => 2,
          'decimalPlaces' => 2,
          'type' => 'monetary',
        ),
      ),
      'lines' => 
      array (
        0 => 
        array (
          'id' => 5501,
          'type' => 'lineItem',
          'name' => 'Annual plan',
          'quantity' => 1,
          'value' => 1500,
          'productId' => NULL,
        ),
        1 => 
        array (
          'id' => 5502,
          'type' => 'discount',
          'name' => 'Welcome discount',
          'quantity' => 1,
          'value' => -300,
          'productId' => NULL,
        ),
      ),
      'distribution' => NULL,
      'refundDistribution' => NULL,
    ),
  ),
  'collaboratorGroupStructure' => 
  array (
    'mode' => 'ask',
    'data' => 
    array (
      'group' => 
      array (
        'id' => 4,
        'name' => 'Northeast team',
      ),
      'members' => 
      array (
        0 => 
        array (
          'collaboratorId' => 63,
          'name' => 'Dana Director',
          'status' => 'active',
          'metadata' => 
          array (
            'rank' => 1,
          ),
        ),
        1 => 
        array (
          'collaboratorId' => 57,
          'name' => 'Morgan Manager',
          'status' => 'active',
          'metadata' => 
          array (
            'rank' => 2,
          ),
        ),
        2 => 
        array (
          'collaboratorId' => 42,
          'name' => 'Riley Rep',
          'status' => 'active',
          'metadata' => 
          array (
            'rank' => 3,
          ),
        ),
      ),
      'for' => 
      array (
        'type' => 'distributor',
        'id' => 2,
      ),
      'distributor' => 
      array (
        'id' => 2,
        'name' => 'Partner leaderboard',
      ),
      'distribution' => 
      array (
        'id' => 21,
        'periodEnd' => '2026-12-01T00:00:00+00:00',
      ),
      'metricType' => 'referredSiteVisit',
      'distributorMetricTypeId' => 55,
      'triggeringCollaborator' => 
      array (
        'id' => 42,
        'name' => 'Riley Rep',
      ),
      'opportunityId' => 615,
      'points' => NULL,
      'cause' => 
      array (
        'id' => '9f2c4e1a7b3d5c60',
        'type' => NULL,
        'causeId' => NULL,
      ),
      'sale' => NULL,
    ),
  ),
);
