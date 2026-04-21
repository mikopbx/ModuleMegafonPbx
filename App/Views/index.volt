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
    <div class="ten wide field disability">
        <label>{{ t._('module_megafon_crmToken') }}</label>
        {{ form.render('crmToken') }}
    </div>
    <div class="ten wide field">
        <label>{{ t._('module_megafon_userMatchMode') }}</label>
        {{ form.render('userMatchMode') }}
    </div>

    <h4 class="ui dividing header">{{ t._('module_megafon_matchConflictsTitle') }}</h4>
    {% if matchConflicts['ok'] is not defined or not matchConflicts['ok'] %}
        <div class="ui small warning message">
            {{ t._('module_megafon_matchConflictsCtiUnavailable') }}
        </div>
    {% elseif matchConflicts['conflicts'] is empty %}
        <div class="ui small positive message">
            {{ t._('module_megafon_matchConflictsNone') }}
        </div>
    {% else %}
        <div class="ui small warning message">
            {{ t._('module_megafon_matchConflictsHint') }}
        </div>
        <table class="ui compact celled small table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>{{ t._('module_megafon_matchConflictsByExt') }} / {{ t._('module_megafon_matchConflictsByMobile') }}</th>
                    <th>1C users</th>
                </tr>
            </thead>
            <tbody>
            {% for conflict in matchConflicts['conflicts'] %}
                <tr>
                    <td>{{ loop.index }}</td>
                    <td>
                        {% if conflict['type'] == 'ext' %}
                            <i class="hashtag icon"></i>{{ t._('module_megafon_matchConflictsByExt') }}: <b>{{ conflict['key'] }}</b>
                        {% else %}
                            <i class="mobile alternate icon"></i>{{ t._('module_megafon_matchConflictsByMobile') }}: <b>{{ conflict['key'] }}</b>
                        {% endif %}
                    </td>
                    <td>
                        <ul style="margin:0; padding-left:1.2em;">
                        {% for user in conflict['users'] %}
                            <li>
                                <b>{{ user['name'] }}</b>
                                {% if user['extension'] is defined and user['extension'] != '' %} (ext {{ user['extension'] }}){% endif %}
                                {% if user['mobile'] is defined and user['mobile'] != '' %} — {{ user['mobile'] }}{% endif %}
                            </li>
                        {% endfor %}
                        </ul>
                    </td>
                </tr>
            {% endfor %}
            </tbody>
        </table>
    {% endif %}

    {{ partial("partials/submitbutton",['indexurl':'pbx-extension-modules/index/']) }}
</form>