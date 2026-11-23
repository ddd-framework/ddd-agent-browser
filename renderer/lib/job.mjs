// The render job as the calling application sends it (already validated there); checked again here so a wrong caller
// gets a 400 instead of a strange render.

const badRequest = message => Object.assign(new Error(message), { statusCode: 400 });

const integerInRange = (body, field, fallback, minimum, maximum) => {
  const value = body[field] ?? fallback;
  if (!Number.isInteger(value) || value < minimum || value > maximum) throw badRequest(`${field} must be a whole number between ${minimum} and ${maximum}`);
  return value;
};

const numberInRange = (body, field, fallback, minimum, maximum) => {
  const value = body[field] ?? fallback;
  if (typeof value !== 'number' || !Number.isFinite(value) || value < minimum || value > maximum) throw badRequest(`${field} must be a number between ${minimum} and ${maximum}`);
  return value;
};

const booleanField = (body, field, fallback) => {
  const value = body[field] ?? fallback;
  if (typeof value !== 'boolean') throw badRequest(`${field} must be true or false`);
  return value;
};

const choiceField = (body, field, fallback, choices) => {
  const value = body[field] ?? fallback;
  if (!choices.includes(value)) throw badRequest(`${field} must be one of ${choices.join(', ')}`);
  return value;
};

/**
 * @param {any} body The JSON body
 * @returns {object} The normalized job
 */
export const validateScreenshotJob = body => {
  if (!body || typeof body !== 'object' || Array.isArray(body)) throw badRequest('send a JSON object');
  if (typeof body.url !== 'string' || body.url === '' || body.url.length > 2048) throw badRequest('url is required (at most 2048 characters)');
  const job = {
    url: body.url,
    device: choiceField(body, 'device', 'desktop', ['desktop', 'mobile']),
    viewportWidth: integerInRange(body, 'viewportWidth', 1024, 320, 1920),
    viewportHeight: integerInRange(body, 'viewportHeight', 768, 320, 8000),
    fullPage: booleanField(body, 'fullPage', false),
    maxPageHeight: integerInRange(body, 'maxPageHeight', 20000, 1000, 20000),
    format: choiceField(body, 'format', 'png', ['png', 'jpeg']),
    quality: integerInRange(body, 'quality', 80, 0, 100),
    density: integerInRange(body, 'density', 1, 1, 3),
    waitUntil: choiceField(body, 'waitUntil', 'domcontentloaded', ['domcontentloaded', 'load', 'networkidle0', 'networkidle2']),
    minWaitSeconds: numberInRange(body, 'minWaitSeconds', 2, 0, 10),
    domTimeoutSeconds: integerInRange(body, 'domTimeoutSeconds', 20, 5, 45),
    javascriptEnabled: booleanField(body, 'javascriptEnabled', true),
    redirectEnabled: booleanField(body, 'redirectEnabled', true),
    maxRedirectHops: integerInRange(body, 'maxRedirectHops', 5, 0, 10),
    omitBackground: booleanField(body, 'omitBackground', false),
    cookieBanners: choiceField(body, 'cookieBanners', 'reject', ['reject', 'hide', 'off']),
    // measurement only (measure/measure.mjs): auto = Chrome, Camoufox once after a challenge
    engine: choiceField(body, 'engine', 'auto', ['auto', 'chrome', 'camoufox']),
    waitForSelector: null,
  };
  if (body.waitForSelector !== undefined && body.waitForSelector !== null) {
    if (typeof body.waitForSelector !== 'string' || body.waitForSelector.length > 200) throw badRequest('waitForSelector must be a CSS selector of at most 200 characters');
    job.waitForSelector = body.waitForSelector;
  }
  return job;
};
