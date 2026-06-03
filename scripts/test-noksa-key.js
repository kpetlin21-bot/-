function normalizeHouseNum(s) {
  if (!s) return '';
  return s.toString()
    .toLowerCase()
    .trim()
    .replace(/к(\d)/g, '/$1')
    .replace(/\s+/g, '')
    .replace(/[«»""]/g, '');
}

function normalizeStreet(s) {
  if (!s) return '';
  return s.toLowerCase()
    .replace(/проспект|улица|переулок|бульвар|пер\.|ул\.|пр\.|пр-кт/g, '')
    .replace(/ё/g, 'е')
    .trim();
}

function tbKeyFromLabel(label) {
  const raw = String(label || '').trim();
  const commaIdx = raw.indexOf(',');
  let streetPart = raw;
  let tailPart = '';
  if (commaIdx !== -1) {
    streetPart = raw.slice(0, commaIdx).trim();
    tailPart = raw.slice(commaIdx + 1).trim();
  }

  let num = '';
  if (tailPart) {
    const m = tailPart.match(/(?:корп\.?|к\.|д\.?|дом)?\s*(\d[\d/а-яА-Яa-zA-Z]*)/i);
    if (m) num = normalizeHouseNum(m[1]);
  }

  if (!num) {
    const clean = streetPart.replace(/\s+/g, ' ').trim();
    const parts = clean.split(' ');
    let numIdx = -1;
    for (let i = parts.length - 1; i >= 0; i--) {
      if (/^\d/.test(parts[i])) { numIdx = i; break; }
    }
    if (numIdx === -1) return normalizeHouseNum(clean);
    num = normalizeHouseNum(parts[numIdx]);
    streetPart = parts.slice(0, numIdx).join(' ');
  }

  return num + '|' + normalizeStreet(streetPart);
}

const tests = [
  'Нокса парк, корп. 3',
  'Нокса парк, корп. 12',
  'ул. Примерная 5',
  'Нокса парк 7',
];

for (const t of tests) {
  console.log(JSON.stringify(t), '→', tbKeyFromLabel(t));
}
