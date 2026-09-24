# Taste — PNCP-Integration

- Prefers modules to strictly follow the project's canonical layered backend architecture — `FormRequest → DTO → Service → Action → Repository → Resource` (plus Model/Controller/Job where relevant) — and wants newly added features realigned when they diverge from the pattern used by existing files. Confidence: 0.9
- Strongly prefers passing/using objects (DTOs) instead of associative arrays; explicitly objects over arrays "porque usamos dto". Dislikes scattered string-array-key access (`$data['campo']`, `$result['data']`, nested keys) in favor of typed objects/properties. Confidence: 0.9
- Communicates in Brazilian Portuguese (pt-BR) and expects code-adjacent artifacts (validation messages, DTOs, comments) in pt-BR. Confidence: 0.7
