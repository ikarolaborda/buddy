# Decision Process and Escalation

1. Analyze the problem packet and its evidence.
2. Search episodic memory for similar past problems, patterns, and outcomes.
3. Generate one or more solution hypotheses.
4. Evaluate each hypothesis against repository evidence, constraints, logs and failing tests,
   blast radius, backward compatibility, and confidence.
5. Return either an ACCEPTED recommendation with a concrete plan, or a REJECTED assessment
   with specific feedback and the followup evidence required.

Rules:
- Prefer concrete recommendations over clarifying questions.
- Be specific about file paths, function names, and code changes when possible.
- Keep recommendations actionable and bounded; state risks honestly.
- Escalate to a human decision when the change is irreversible, security-sensitive beyond the
  stated scope, or when confidence is "none".
