/* JTSOFTWARE I.T. SERVICES — shared browser behaviour */
(function () {
  'use strict';

  /* Sidebar on small screens -------------------------------------- */
  var toggle = document.getElementById('menuToggle');
  var sidebar = document.getElementById('sidebar');
  if (toggle && sidebar) {
    toggle.addEventListener('click', function () { sidebar.classList.toggle('open'); });
    document.addEventListener('click', function (ev) {
      if (window.innerWidth <= 860 && sidebar.classList.contains('open') &&
          !sidebar.contains(ev.target) && !toggle.contains(ev.target)) {
        sidebar.classList.remove('open');
      }
    });
  }

  /* Modals ---------------------------------------------------------- */
  window.openModal = function (id, presets) {
    var m = document.getElementById(id);
    if (!m) return;
    var form = m.querySelector('form');
    if (form) {
      form.reset();
      // Clear previous values that reset() cannot restore (hidden ids).
      form.querySelectorAll('[data-clear]').forEach(function (el) { el.value = ''; });
    }
    if (presets) {
      Object.keys(presets).forEach(function (name) {
        var field = m.querySelector('[name="' + name + '"]');
        if (!field) return;
        if (field.type === 'checkbox') { field.checked = !!Number(presets[name]); }
        else { field.value = presets[name] === null ? '' : presets[name]; }
      });
    }
    var title = m.getAttribute('data-title-' + (presets && presets.id ? 'edit' : 'new'));
    var h = m.querySelector('.modal-head h3');
    if (title && h) h.textContent = title;
    m.classList.add('open');
    var first = m.querySelector('input:not([type=hidden]):not([readonly]), select, textarea');
    if (first) setTimeout(function () { first.focus(); }, 40);
  };

  window.closeModal = function (id) {
    var m = typeof id === 'string' ? document.getElementById(id) : id;
    if (m) m.classList.remove('open');
  };

  document.addEventListener('click', function (ev) {
    var open = ev.target.closest('[data-modal]');
    if (open) {
      ev.preventDefault();
      var preset = open.getAttribute('data-row');
      window.openModal(open.getAttribute('data-modal'), preset ? JSON.parse(preset) : null);
      return;
    }
    if (ev.target.classList.contains('modal-backdrop')) { window.closeModal(ev.target); }
    if (ev.target.closest('[data-close]')) {
      ev.preventDefault();
      window.closeModal(ev.target.closest('.modal-backdrop'));
    }
  });

  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape') {
      document.querySelectorAll('.modal-backdrop.open').forEach(function (m) { m.classList.remove('open'); });
    }
  });

  /* Confirm destructive actions ------------------------------------ */
  document.addEventListener('submit', function (ev) {
    var msg = ev.target.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) { ev.preventDefault(); }
  });

  /* Auto-submit filter forms when a select changes ------------------ */
  document.querySelectorAll('form[data-autosubmit] select').forEach(function (sel) {
    sel.addEventListener('change', function () { sel.form.submit(); });
  });

  /* Line-item editor used by quotations and invoices ---------------- */
  var itemsBody = document.getElementById('itemRows');
  if (itemsBody) {
    var catalog = window.PRODUCT_CATALOG || [];

    function recalc() {
      var itemsTotal = 0;
      itemsBody.querySelectorAll('tr').forEach(function (tr) {
        var qty = parseFloat(tr.querySelector('.i-qty').value) || 0;
        var price = parseFloat(tr.querySelector('.i-price').value) || 0;
        var line = qty * price;
        tr.querySelector('.i-line').textContent = line.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        itemsTotal += line;
      });
      var num = function (id) { return parseFloat((document.getElementById(id) || {}).value) || 0; };
      var labor = num('labor_charge'), disc = num('discount'), tax = num('tax');
      var grand = itemsTotal + labor - disc + tax;
      var fmt = function (v) { return v.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
      var set = function (id, v) { var el = document.getElementById(id); if (el) el.textContent = fmt(v); };
      set('sumItems', itemsTotal);
      set('sumGrand', grand);
      var hidden = document.getElementById('items_total');
      if (hidden) hidden.value = itemsTotal.toFixed(2);
    }
    window.recalcItems = recalc;

    window.addItemRow = function (preset) {
      preset = preset || {};
      var i = itemsBody.children.length;
      var tr = document.createElement('tr');
      var opts = '<option value="">Custom item / service</option>' + catalog.map(function (p) {
        return '<option value="' + p.id + '" data-price="' + p.selling_price + '" data-name="' +
               p.name.replace(/"/g, '&quot;') + '"' + (String(preset.product_id) === String(p.id) ? ' selected' : '') +
               '>' + p.name + ' (' + p.stock_qty + ' in stock)</option>';
      }).join('');
      tr.innerHTML =
        '<td><select class="input i-prod" name="item_product[]">' + opts + '</select></td>' +
        '<td><input class="input i-desc" name="item_desc[]" required value="' + (preset.description || '').replace(/"/g, '&quot;') + '"></td>' +
        '<td style="width:95px"><input class="input i-qty" name="item_qty[]" type="number" min="0.01" step="0.01" value="' + (preset.quantity || 1) + '"></td>' +
        '<td style="width:130px"><input class="input i-price" name="item_price[]" type="number" min="0" step="0.01" value="' + (preset.unit_price || 0) + '"></td>' +
        '<td class="num i-line" style="width:110px">0.00</td>' +
        '<td style="width:40px"><button type="button" class="linkbtn i-del" title="Remove line">&times;</button></td>';
      itemsBody.appendChild(tr);

      tr.querySelector('.i-prod').addEventListener('change', function () {
        var opt = this.options[this.selectedIndex];
        if (opt.value) {
          tr.querySelector('.i-desc').value = opt.getAttribute('data-name');
          tr.querySelector('.i-price').value = opt.getAttribute('data-price');
        }
        recalc();
      });
      tr.querySelector('.i-del').addEventListener('click', function () { tr.remove(); recalc(); });
      tr.querySelectorAll('.i-qty, .i-price').forEach(function (el) { el.addEventListener('input', recalc); });
      recalc();
    };

    ['labor_charge', 'discount', 'tax'].forEach(function (id) {
      var el = document.getElementById(id);
      if (el) el.addEventListener('input', recalc);
    });

    (window.EXISTING_ITEMS || []).forEach(function (row) { window.addItemRow(row); });
    if (!itemsBody.children.length) { window.addItemRow(); }
    recalc();
  }
})();
