// public/admin/app.js

document.addEventListener('ea.collection.item-added', (event) => {
  const newItem = event.detail.newElement;
  const textareas = newItem.querySelectorAll('textarea');
  
  textareas.forEach(textarea => {
    // On utilise setTimeout pour s'assurer que le textarea est bien "prêt" dans la page
    // avant d'essayer de le transformer.
    setTimeout(() => {
      if (CKEDITOR.instances[textarea.id]) {
        CKEDITOR.instances[textarea.id].destroy(true);
      }

      CKEDITOR.replace(textarea.id, {
        toolbar: [
          ['Bold', 'Italic', 'Underline', 'RemoveFormat'],
          ['NumberedList', 'BulletedList', 'Blockquote'],
          ['Link', 'Unlink'],
          ['Undo', 'Redo', 'Source'],
        ],
      });
      console.log(`👍 Initialisation de CKEditor pour #${textarea.id} (après un court délai)`);
    }, 10); // Un délai minuscule de 10 millisecondes suffit
  });
});