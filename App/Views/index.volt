<form class="ui large grey segment form" id="module-megafon-pbx-form">
    {{ form.render('id') }}

    <div class="ten wide field disability">
        <label >{{ t._('module_megafon_pbxAuthApiKey') }}</label>
        {{ form.render('authApiKey') }}
    </div>
    <div class="ten wide field disability">
        <label >{{ t._('module_megafon_pbxhost') }}</label>
        {{ form.render('host') }}
    </div>
    <div class="ten wide field disability">
        <label >{{ t._('module_megafon_gap') }}</label>
        {{ form.render('gap') }}
    </div>
    <div class="ten wide field">
        <label>{{ t._('module_megafon_FieldNumberTitle') }}</label>
        {{ form.render('extField') }}
    </div>
    {{ partial("partials/submitbutton",['indexurl':'pbx-extension-modules/index/']) }}
</form>