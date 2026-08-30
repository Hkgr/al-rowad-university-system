export function transcriptGenerationMetadata(generation = {}) {
  const generatedBy = generation?.generated_by && typeof generation.generated_by === 'object'
    ? generation.generated_by
    : {}
  let generatedAt = '—'

  if (generation?.generated_at) {
    const timestamp = new Date(generation.generated_at)
    if (!Number.isNaN(timestamp.getTime())) {
      try {
        generatedAt = new Intl.DateTimeFormat('ar-SY', {
          timeZone: generation.timezone || 'Asia/Damascus',
          year: 'numeric', month: '2-digit', day: '2-digit',
          hour: '2-digit', minute: '2-digit', hour12: false,
        }).format(timestamp)
      } catch {
        generatedAt = '—'
      }
    }
  }

  return {
    generatedAt,
    generatedBy: generatedBy.display_name || generatedBy.username || '—',
    organizationalUnit: generatedBy.organizational_unit?.name
      || generatedBy.organizational_unit?.code
      || generatedBy.organizational_unit_name
      || generatedBy.organizational_unit_code
      || '',
  }
}
