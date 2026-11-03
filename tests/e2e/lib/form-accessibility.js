// DOM half of the shared form contract. PHP owns stable control/hint/error IDs;
// this check proves the fully rendered page has no duplicate owners, dead
// aria-describedby references or generated hints/errors without an owner.

async function formReferenceProblems(page) {
  return page.evaluate(() => {
    const problems = [];
    const idOwners = new Map();
    document.querySelectorAll('[id]').forEach((element) => {
      const id = element.id;
      if (!idOwners.has(id)) {
        idOwners.set(id, []);
      }
      idOwners.get(id).push(element);
    });

    idOwners.forEach((owners, id) => {
      if (owners.length > 1) {
        problems.push(`duplicate id #${id} (${owners.length} owners)`);
      }
    });

    const references = new Map();
    document.querySelectorAll('[aria-describedby]').forEach((owner) => {
      const ids = owner.getAttribute('aria-describedby').split(/\s+/).filter(Boolean);
      if (new Set(ids).size !== ids.length) {
        problems.push(`duplicate aria-describedby token on #${owner.id || owner.tagName.toLowerCase()}`);
      }
      ids.forEach((id) => {
        if (!references.has(id)) {
          references.set(id, []);
        }
        references.get(id).push(owner);
        const targets = idOwners.get(id) || [];
        if (targets.length !== 1) {
          problems.push(`aria-describedby target #${id} has ${targets.length} owners`);
        }
      });
    });

    document.querySelectorAll('[id^="form-"][id$="-hint"]').forEach((hint) => {
      const inactive = hint.hidden || hint.closest('[hidden]') !== null;
      if ((references.get(hint.id) || []).length === 0 && !inactive) {
        problems.push(`generated hint #${hint.id} has no describing owner`);
      }
    });

    document.querySelectorAll('.field-error[id^="form-"][id$="-error"]').forEach((error) => {
      const owners = references.get(error.id) || [];
      if (owners.length !== 1) {
        problems.push(`generated error #${error.id} has ${owners.length} describing owners`);
      } else if (owners[0].getAttribute('aria-invalid') !== 'true') {
        problems.push(`generated error #${error.id} belongs to a control without aria-invalid=true`);
      }
    });

    document.querySelectorAll('[aria-invalid="true"]').forEach((control) => {
      const ids = (control.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);
      if (!ids.some((id) => id.endsWith('-error'))) {
        problems.push(`invalid control #${control.id || control.tagName.toLowerCase()} has no error reference`);
      }
    });

    return problems;
  });
}

module.exports = { formReferenceProblems };
