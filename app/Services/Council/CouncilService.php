<?php

namespace App\Services\Council;

use App\DTOs\MemorySearchPage;
use App\Enums\ArtifactType;
use App\Models\BuddyTask;
use App\Services\TaskStateService;
use Illuminate\Support\Facades\Log;

/*
 * Falsification-first council (plan 2026-07-22-llm-council, ADR 0009),
 * implementing the operator's two papers with one deliberate division
 * of labor: PHP does everything mechanical (evidence-reference
 * validation, support fractions, defeat accounting, lexicographic
 * ranking, family-correlation disclosure) and the chairman model only
 * frames, and finally narrates, constrained to the computed ordering.
 * Packet evidence is testimony: members may CHALLENGE with cited
 * testimony but a hard defeat additionally requires the chairman-frame
 * kill condition to be contradicted by a cited packet item, and even
 * then the verdict labels it "testimony-defeat", never a Tier 1-2
 * defeat. Underdetermined outcomes with a discriminator list are the
 * expected product, not a failure mode.
 */
class CouncilService
{
    public function __construct(
        protected CouncilClient $client,
        protected TaskStateService $state,
    ) {}

    /**
     * @return array{verdict: array<string, mixed>, transcript: array<string, mixed>, usage: array<string, int>}
     */
    public function deliberate(BuddyTask $task, MemorySearchPage $memoryPage, ?string $claimOwner = null): array
    {
        $packet = $this->packet($task, $memoryPage);
        $usage = ['prompt_tokens' => 0, 'completion_tokens' => 0];
        $transcript = ['packet_item_ids' => array_keys($packet['items']), 'rounds' => []];

        $chairman = (array) config('buddy_agents.council.chairman');
        $members = array_values((array) config('buddy_agents.council.members'));

        // R0: chairman frames hypotheses with kill conditions.
        $frame = $this->client->ask($chairman, $this->framingSystem(), $this->framingPrompt($packet));
        $this->tally($usage, $frame['usage']);

        if ($frame['json'] === null) {
            throw new \RuntimeException('Council chairman framing failed: '.($frame['error'] ?? 'unknown'));
        }

        $hypotheses = $this->normalizeHypotheses($frame['json']);
        $transcript['rounds']['frame'] = $frame['json'];
        $this->checkpoint($task, 'frame', $frame['json']);
        $this->beat($task, $claimOwner);

        // R1: independent positions, in parallel, shared packet.
        $positions = $this->client->askAll(
            $members,
            $this->positionSystem(),
            fn ($m) => $this->positionPrompt($packet, $hypotheses),
        );
        $this->tallyAll($usage, $positions);

        $present = array_values(array_filter($members, fn ($m) => ($positions[$m['key']]['json'] ?? null) !== null));
        $absent = array_values(array_diff(array_column($members, 'key'), array_column($present, 'key')));

        if (count($present) < (int) config('buddy_agents.council.min_positions', 3)) {
            throw new \RuntimeException('Council quorum failed: only '.count($present).' positions ('.implode(',', $absent).' absent)');
        }

        $anonymous = $this->anonymize($present, $positions);
        $transcript['rounds']['positions'] = $anonymous['transcript'];
        $transcript['absent_after_r1'] = $absent;
        $this->checkpoint($task, 'positions', $anonymous['transcript']);
        $this->beat($task, $claimOwner);

        // R2: falsification round over anonymized positions. Membership
        // is frozen: R1 absentees do not attack a debate they never
        // joined.
        $attacks = $this->client->askAll(
            $present,
            $this->falsificationSystem(),
            fn ($m) => $this->falsificationPrompt($packet, $hypotheses, $anonymous['for'][$m['key']]),
        );
        $this->tallyAll($usage, $attacks);
        $transcript['rounds']['attacks'] = array_map(fn ($a) => $a['json'] ?? ['error' => $a['error']], $attacks);
        $this->checkpoint($task, 'attacks', $transcript['rounds']['attacks']);
        $this->beat($task, $claimOwner);

        // Mechanical adjudication inputs: PHP, not a model.
        $tally = $this->adjudicate($packet, $hypotheses, $present, $positions, $attacks);
        $transcript['mechanical_tally'] = $tally;

        // R3: chairman narrates, constrained to the computed ordering.
        $verdictReply = $this->client->ask(
            $chairman,
            $this->verdictSystem(),
            $this->verdictPrompt($packet, $hypotheses, $tally),
        );
        $this->tally($usage, $verdictReply['usage']);

        if ($verdictReply['json'] === null) {
            throw new \RuntimeException('Council verdict narration failed: '.($verdictReply['error'] ?? 'unknown'));
        }

        $verdict = $this->assembleVerdict($verdictReply['json'], $tally, $present, $absent);
        $transcript['rounds']['verdict'] = $verdictReply['json'];

        return ['verdict' => $verdict, 'transcript' => $transcript, 'usage' => $usage];
    }

    /**
     * The shared context packet. Every item gets a stable id; member
     * claims must cite these ids and PHP validates the citations.
     *
     * @return array{summary: string, items: array<string, array<string, mixed>>}
     */
    protected function packet(BuddyTask $task, MemorySearchPage $memoryPage): array
    {
        $items = [];

        foreach ((array) $task->evidence as $i => $evidence) {
            $items['E'.($i + 1)] = ['kind' => 'evidence', 'tier' => 'testimony', 'content' => is_string($evidence) ? $evidence : json_encode($evidence)];
        }

        foreach ($task->artifacts()->get() as $i => $artifact) {
            $items['A'.($i + 1)] = [
                'kind' => 'artifact:'.$artifact->type->value,
                'tier' => 'testimony',
                'content' => mb_substr((string) $artifact->content, 0, (int) config('buddy_agents.council.artifact_chars', 4000)),
            ];
        }

        foreach ($memoryPage->results as $i => $hit) {
            $items['M'.($i + 1)] = ['kind' => 'memory', 'tier' => 'testimony', 'content' => (string) $hit->summary, 'score' => $hit->score];
        }

        return [
            'summary' => (string) $task->task_summary,
            'problem_type' => $task->problem_type->value,
            'constraints' => (array) $task->constraints,
            'requested_outcome' => (string) ($task->requested_outcome ?? ''),
            'memory_degraded' => $memoryPage->degraded,
            'items' => $items,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeHypotheses(array $frame): array
    {
        $hypotheses = [];

        foreach ((array) ($frame['hypotheses'] ?? []) as $i => $h) {
            $hypotheses[] = [
                'id' => (string) ($h['id'] ?? 'H'.($i + 1)),
                'statement' => (string) ($h['statement'] ?? ''),
                'kill_conditions' => array_values((array) ($h['kill_conditions'] ?? [])),
            ];
        }

        if ($hypotheses === []) {
            throw new \RuntimeException('Chairman frame produced no hypotheses.');
        }

        return $hypotheses;
    }

    /**
     * Anonymize authorship for the falsification round: Member A..E,
     * randomized per council, so no model can defer to a brand.
     *
     * @param  array<int, array<string, mixed>>  $present
     * @return array{for: array<string, string>, transcript: array<string, mixed>, alias: array<string, string>}
     */
    protected function anonymize(array $present, array $positions): array
    {
        $letters = ['A', 'B', 'C', 'D', 'E'];
        $keys = array_column($present, 'key');
        shuffle($keys);

        $alias = [];
        $blocks = [];

        foreach ($keys as $i => $key) {
            $alias[$key] = 'Member '.$letters[$i];
            $blocks[$alias[$key]] = $positions[$key]['json'];
        }

        $shared = '';

        foreach ($blocks as $name => $position) {
            $shared .= "\n### {$name}\n".json_encode($position, JSON_PRETTY_PRINT)."\n";
        }

        $for = [];

        foreach ($keys as $key) {
            $for[$key] = "You are {$alias[$key]}. All positions (including your own) follow:\n".$shared;
        }

        return [
            'for' => $for,
            'alias' => $alias,
            'transcript' => array_combine(array_map(fn ($k) => $alias[$k].' ('.$k.')', $keys), array_map(fn ($k) => $positions[$k]['json'], $keys)),
        ];
    }

    /**
     * Mechanical adjudication: citation validation, support fractions
     * over respondents, defeat accounting, lexicographic ranking.
     *
     * @param  array<int, array<string, mixed>>  $hypotheses
     * @param  array<int, array<string, mixed>>  $present
     * @return array<string, mixed>
     */
    protected function adjudicate(array $packet, array $hypotheses, array $present, array $positions, array $attacks): array
    {
        $itemIds = array_keys($packet['items']);
        $respondentCount = count($present);
        $perHypothesis = [];

        foreach ($hypotheses as $hypothesis) {
            $hid = $hypothesis['id'];
            $support = 0;
            $reject = 0;
            $confidences = [];
            $families = [];
            $challenges = [];
            $testimonyDefeats = [];
            $fabricatedRefs = 0;

            foreach ($present as $member) {
                $position = $positions[$member['key']]['json'] ?? [];

                foreach ((array) ($position['stances'] ?? []) as $stance) {
                    if ((string) ($stance['hypothesis_id'] ?? '') !== $hid) {
                        continue;
                    }

                    $validRefs = array_values(array_intersect((array) ($stance['evidence_refs'] ?? []), $itemIds));

                    if ((string) ($stance['stance'] ?? '') === 'support') {
                        $support++;
                        $families[$member['family']] = true;
                    }

                    if ((string) ($stance['stance'] ?? '') === 'reject') {
                        $reject++;
                    }

                    if (isset($stance['confidence'])) {
                        $confidences[] = (float) $stance['confidence'];
                    }

                    if (count($validRefs) < count((array) ($stance['evidence_refs'] ?? []))) {
                        $fabricatedRefs++;
                    }
                }
            }

            foreach ($attacks as $memberKey => $attack) {
                foreach ((array) (($attack['json'] ?? [])['defeaters'] ?? []) as $defeater) {
                    if ((string) ($defeater['hypothesis_id'] ?? '') !== $hid) {
                        continue;
                    }

                    $refs = (array) ($defeater['evidence_refs'] ?? []);
                    $validRefs = array_values(array_intersect($refs, $itemIds));
                    $entry = [
                        'by' => $memberKey,
                        'text' => mb_substr((string) ($defeater['text'] ?? ''), 0, 600),
                        'evidence_refs' => $validRefs,
                        'kill_condition_hit' => (string) ($defeater['kill_condition'] ?? ''),
                    ];

                    // Papers' rule, adapted honestly: cited testimony
                    // contradicting a declared kill condition is the
                    // strongest defeat this council can execute; an
                    // uncited objection is only a challenge.
                    $hitsKill = in_array($entry['kill_condition_hit'], $hypotheses[array_search($hid, array_column($hypotheses, 'id'))]['kill_conditions'] ?? [], true);

                    if ($validRefs !== [] && $hitsKill) {
                        $testimonyDefeats[] = $entry;
                    } else {
                        if ($refs !== [] && $validRefs === []) {
                            $fabricatedRefs++;
                            $entry['downgraded'] = 'fabricated evidence refs';
                        }

                        $challenges[] = $entry;
                    }
                }
            }

            $supp = $respondentCount > 0 ? round($support / $respondentCount, 3) : 0.0;
            $meanConfidence = $confidences !== [] ? round(array_sum($confidences) / count($confidences), 3) : null;
            $confidenceSpread = count($confidences) > 1 ? round(max($confidences) - min($confidences), 3) : 0.0;

            $perHypothesis[$hid] = [
                'statement' => $hypothesis['statement'],
                'support' => $support,
                'reject' => $reject,
                'supp_fraction' => $supp,
                'supporting_families' => count($families),
                'mean_confidence' => $meanConfidence,
                'confidence_spread' => $confidenceSpread,
                'testimony_defeats' => $testimonyDefeats,
                'challenges' => $challenges,
                'fabricated_ref_count' => $fabricatedRefs,
                'status' => $testimonyDefeats !== [] ? 'testimony_defeated' : 'live',
            ];
        }

        // Lexicographic ranking over live hypotheses: not defeated,
        // fewest open challenges, broadest family support, then supp.
        $live = array_filter($perHypothesis, fn ($h) => $h['status'] === 'live');
        uasort($live, function ($a, $b) {
            return [count($a['challenges']), -$a['supporting_families'], -$a['supp_fraction']]
                <=> [count($b['challenges']), -$b['supporting_families'], -$b['supp_fraction']];
        });

        $ranked = array_keys($live);
        $top = $ranked[0] ?? null;
        $second = $ranked[1] ?? null;

        // Output mode by the papers' rules: no survivor = exhaustion;
        // survivors observationally close (supp within noise, both
        // family-diverse) = underdetermined; else unique survivor.
        $mode = 'exhaustion';

        if ($top !== null) {
            $mode = 'unique_survivor';

            if ($second !== null && abs($live[$top]['supp_fraction'] - $live[$second]['supp_fraction']) <= 0.2) {
                $mode = 'underdetermined';
            }
        }

        return [
            'respondents' => array_column($present, 'key'),
            'per_hypothesis' => $perHypothesis,
            'ranking' => $ranked,
            'output_mode' => $mode,
            'disclosure' => [
                'family_spread' => array_count_values(array_column($present, 'family')),
                'chairman_is_member' => in_array((string) config('buddy_agents.council.chairman.model'), array_column($present, 'model'), true),
                'defeat_ceiling' => 'testimony',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function assembleVerdict(array $narration, array $tally, array $present, array $absent): array
    {
        return [
            'output_mode' => $tally['output_mode'],
            'accepted' => (bool) ($narration['accepted'] ?? ($tally['output_mode'] === 'unique_survivor')),
            'confidence' => (string) ($narration['confidence'] ?? 'low'),
            'summary' => (string) ($narration['summary'] ?? ''),
            'recommended_plan' => array_values((array) ($narration['recommended_plan'] ?? [])),
            'findings' => array_values((array) ($narration['findings'] ?? [])),
            'supported_hypotheses' => array_values((array) ($narration['supported_hypotheses'] ?? [])),
            'weak_hypotheses' => array_values((array) ($narration['weak_hypotheses'] ?? [])),
            'defeated' => array_values((array) ($narration['defeated'] ?? [])),
            'dissents' => array_values((array) ($narration['dissents'] ?? [])),
            'proposed_discriminators' => array_values((array) ($narration['proposed_discriminators'] ?? [])),
            'risks' => array_values((array) ($narration['risks'] ?? [])),
            'mechanical_tally' => $tally,
            'members_present' => array_column($present, 'key'),
            'members_absent' => $absent,
        ];
    }

    protected function framingSystem(): string
    {
        return 'You chair a falsification-first engineering council. Decompose the problem into 2-5 materially distinct hypotheses. Each hypothesis MUST carry explicit kill_conditions: observations that, if evidenced, defeat it. Do not evaluate; only frame. Reply with ONLY a JSON object: {"claims": [{"id": "C1", "text": "..."}], "hypotheses": [{"id": "H1", "statement": "...", "kill_conditions": ["..."]}], "open_questions": ["..."]}.';
    }

    protected function framingPrompt(array $packet): string
    {
        return "Problem packet:\n".json_encode($packet, JSON_PRETTY_PRINT);
    }

    protected function positionSystem(): string
    {
        return 'You are one member of a falsification-first engineering council. Evidence items are TESTIMONY supplied by the requesting agent; you may rely on them only by citing their ids. Rules: every stance must cite evidence_refs from the packet item ids or be marked reasoning_only; unsupported assertions are worth almost nothing; propose falsifiers (concrete checks) you cannot run here. Reply with ONLY JSON: {"stances": [{"hypothesis_id": "H1", "stance": "support|reject|underdetermined", "evidence_refs": ["E1"], "reasoning_only": false, "reasoning": "...", "confidence": 0.0}], "new_hypotheses": [{"statement": "...", "kill_conditions": ["..."]}], "proposed_falsifiers": [{"hypothesis_id": "H1", "check": "concrete runnable check"}]}.';
    }

    protected function positionPrompt(array $packet, array $hypotheses): string
    {
        return "Problem packet:\n".json_encode($packet, JSON_PRETTY_PRINT)
            ."\n\nChairman-framed hypotheses:\n".json_encode($hypotheses, JSON_PRETTY_PRINT);
    }

    protected function falsificationSystem(): string
    {
        return 'Falsification round. Attack every position below, including your own; your goal is to DEFEAT hypotheses, not to agree. A defeater only counts when it cites evidence_refs (packet item ids) that contradict one of the hypothesis kill_conditions; name that kill_condition verbatim in kill_condition. Objections without citations are challenges and will be recorded as such. Do not defer to any member; authorship is anonymized. Reply with ONLY JSON: {"defeaters": [{"hypothesis_id": "H1", "target_member": "Member A", "text": "...", "evidence_refs": ["E1"], "kill_condition": "verbatim kill condition text"}], "concessions": [{"hypothesis_id": "H1", "text": "what would change my mind"}]}.';
    }

    protected function falsificationPrompt(array $packet, array $hypotheses, string $anonymizedPositions): string
    {
        return "Problem packet:\n".json_encode($packet, JSON_PRETTY_PRINT)
            ."\n\nHypotheses with kill conditions:\n".json_encode($hypotheses, JSON_PRETTY_PRINT)
            ."\n\n".$anonymizedPositions;
    }

    protected function verdictSystem(): string
    {
        return 'You chair the council. The mechanical tally below is BINDING: you must not reorder the ranking, revive a testimony_defeated hypothesis, or claim a stronger output_mode than computed. Your job is narration: a decision summary faithful to the tally, findings split honestly (findings only where the tally shows cited support with no open challenges; otherwise supported or weak hypotheses), preserved dissents (represent losing positions fairly), and proposed_discriminators (the concrete unrun checks that would settle open questions - these are the council\'s most valuable output). If output_mode is underdetermined say so plainly. Reply with ONLY JSON: {"accepted": true, "confidence": "high|medium|low|none", "summary": "...", "recommended_plan": ["..."], "findings": ["..."], "supported_hypotheses": ["..."], "weak_hypotheses": ["..."], "defeated": ["..."], "dissents": ["..."], "proposed_discriminators": ["..."], "risks": ["..."]}.';
    }

    protected function verdictPrompt(array $packet, array $hypotheses, array $tally): string
    {
        return "Problem summary: {$packet['summary']}\n\nHypotheses:\n".json_encode($hypotheses, JSON_PRETTY_PRINT)
            ."\n\nBINDING mechanical tally:\n".json_encode($tally, JSON_PRETTY_PRINT);
    }

    /*
     * Round checkpoints convert a mid-council crash from "money lost"
     * into a resumable transcript; they double as the audit trail.
     */
    protected function checkpoint(BuddyTask $task, string $round, mixed $payload): void
    {
        try {
            $task->artifacts()->create([
                'type' => ArtifactType::CouncilTranscript,
                'content' => (string) json_encode($payload),
                'metadata' => ['round' => $round],
            ]);
        } catch (\Throwable $e) {
            Log::warning('Council round checkpoint failed', ['round' => $round, 'error' => $e->getMessage()]);
        }
    }

    protected function beat(BuddyTask $task, ?string $claimOwner): void
    {
        if ($claimOwner !== null) {
            $this->state->heartbeat($task, $claimOwner, (int) config('buddy.timeouts.council_lease', 1200));
        }
    }

    /**
     * @param  array<string, int>  $usage
     * @param  array<string, int>  $delta
     */
    protected function tally(array &$usage, array $delta): void
    {
        $usage['prompt_tokens'] += (int) ($delta['prompt_tokens'] ?? 0);
        $usage['completion_tokens'] += (int) ($delta['completion_tokens'] ?? 0);
    }

    protected function tallyAll(array &$usage, array $results): void
    {
        foreach ($results as $result) {
            $this->tally($usage, $result['usage'] ?? []);
        }
    }
}
